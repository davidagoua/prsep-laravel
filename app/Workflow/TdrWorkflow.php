<?php

namespace App\Workflow;

use App\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Enums\TdrState;
use App\Enums\WorkflowAction;
use App\Enums\WorkflowSubject;
use App\Models\Activity;
use App\Models\Tdr;
use App\Models\TdrDocument;
use App\Models\User;
use App\Models\WorkflowCircuit;
use App\Models\WorkflowStep;
use App\Notifications\TdrNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Circuit de validation des TDR : chaque méthode vérifie les droits et l'état,
 * enregistre la transition et prévient les personnes concernées.
 */
class TdrWorkflow
{
    public const DISK = 'local';

    public function create(Activity $activity, User $user, string $title, UploadedFile $file): Tdr
    {
        Gate::forUser($user)->authorize('depositTdr', $activity);

        if ($activity->tdr()->exists()) {
            throw new WorkflowException('Cette activité a déjà un TDR en cours.');
        }

        return DB::transaction(function () use ($activity, $user, $title, $file) {
            $tdr = $activity->tdrs()->create([
                'author_id' => $user->id,
                'title' => $title,
                'state' => TdrState::Draft,
            ]);

            $this->storeDocument($tdr, $user, $file, TdrDocument::KIND_TDR);
            $this->record($tdr, $user, WorkflowAction::Created, null, TdrState::Draft);

            return $tdr;
        });
    }

    public function replaceDocument(Tdr $tdr, User $user, UploadedFile $file, ?string $comment = null): TdrDocument
    {
        Gate::forUser($user)->authorize('edit', $tdr);

        return DB::transaction(function () use ($tdr, $user, $file, $comment) {
            $document = $this->storeDocument($tdr, $user, $file, TdrDocument::KIND_TDR);
            $this->record($tdr, $user, WorkflowAction::NewVersion, $tdr->state, $tdr->state, comment: $comment, meta: ['version' => $document->version]);

            return $document;
        });
    }

    /**
     * Brouillon : entre à la première étape du circuit actif.
     * À corriger : reprend à l'étape qui a renvoyé le dossier.
     */
    public function submit(Tdr $tdr, User $user, ?string $comment = null): Tdr
    {
        Gate::forUser($user)->authorize('edit', $tdr);

        if (! $tdr->document()->exists()) {
            throw new WorkflowException('Déposez le fichier du TDR avant de le soumettre.');
        }

        return DB::transaction(function () use ($tdr, $user, $comment) {
            $from = $tdr->state;

            if ($from === TdrState::Draft) {
                $circuit = WorkflowCircuit::activeFor(WorkflowSubject::Tdr, $tdr->activity->volet);
                $step = $circuit?->firstStep();
                if (! $step) {
                    throw new WorkflowException('Aucun circuit de validation n\'est configuré pour le volet '.$tdr->activity->volet->label().'.');
                }
                $tdr->circuit()->associate($circuit);
                $tdr->submitted_at = now();
                $action = WorkflowAction::Submitted;
            } else {
                $step = $tdr->returnedFromStep ?? $tdr->circuit->firstStep();
                $action = WorkflowAction::Resubmitted;
            }

            $tdr->fill([
                'state' => TdrState::InReview,
                'current_step_id' => $step->id,
                'returned_from_step_id' => null,
                'step_entered_at' => now(),
            ])->save();

            $this->record($tdr, $user, $action, $from, TdrState::InReview, toStep: $step, comment: $comment);
            $this->notifyHandlers($tdr, $step, $user);

            return $tdr;
        });
    }

    /**
     * Valide l'étape courante. À une étape « avec avis », l'avis accordé / injonction est obligatoire.
     */
    public function approve(Tdr $tdr, User $user, ?string $comment = null, ?bool $granted = null, ?bool $injunction = null): Tdr
    {
        Gate::forUser($user)->authorize('decide', $tdr);

        $step = $tdr->currentStep;
        if ($step->gives_opinion && $granted === null) {
            throw new WorkflowException('Indiquez si le TDR est accordé avant de valider.');
        }

        return DB::transaction(function () use ($tdr, $user, $comment, $granted, $injunction, $step) {
            if ($step->gives_opinion) {
                $tdr->coordination_granted = $granted;
                $tdr->injunction = (bool) $injunction;
            }

            $next = $tdr->circuit->stepAfter($step);
            $meta = $step->gives_opinion ? ['granted' => $granted, 'injunction' => (bool) $injunction] : null;

            if ($next) {
                $tdr->fill(['current_step_id' => $next->id, 'step_entered_at' => now()])->save();
                $this->record($tdr, $user, WorkflowAction::Approved, TdrState::InReview, TdrState::InReview, $step, $next, $comment, $meta);
                $this->notifyHandlers($tdr, $next, $user);
            } else {
                $tdr->fill([
                    'state' => TdrState::Approved,
                    'current_step_id' => null,
                    'step_entered_at' => null,
                    'approved_at' => now(),
                ])->save();
                $this->record($tdr, $user, WorkflowAction::Approved, TdrState::InReview, TdrState::Approved, $step, null, $comment, $meta);
                $this->notifyOwners($tdr, $user, TdrNotification::APPROVED, $comment);
            }

            return $tdr;
        });
    }

    public function requestChanges(Tdr $tdr, User $user, string $comment): Tdr
    {
        Gate::forUser($user)->authorize('decide', $tdr);

        if (trim($comment) === '') {
            throw new WorkflowException('Expliquez ce qui doit être corrigé.');
        }

        return DB::transaction(function () use ($tdr, $user, $comment) {
            $step = $tdr->currentStep;

            $tdr->fill([
                'state' => TdrState::ChangesRequested,
                'current_step_id' => null,
                'returned_from_step_id' => $step->id,
                'step_entered_at' => null,
            ])->save();

            $this->record($tdr, $user, WorkflowAction::ChangesRequested, TdrState::InReview, TdrState::ChangesRequested, $step, null, $comment);
            $this->notifyOwners($tdr, $user, TdrNotification::RETURNED, $comment);

            return $tdr;
        });
    }

    public function abandon(Tdr $tdr, User $user, ?string $comment = null): Tdr
    {
        Gate::forUser($user)->authorize('edit', $tdr);

        return DB::transaction(function () use ($tdr, $user, $comment) {
            $from = $tdr->state;
            $tdr->fill(['state' => TdrState::Abandoned, 'returned_from_step_id' => null])->save();
            $this->record($tdr, $user, WorkflowAction::Abandoned, $from, TdrState::Abandoned, comment: $comment);

            return $tdr;
        });
    }

    public function start(Tdr $tdr, User $user): Tdr
    {
        Gate::forUser($user)->authorize('execute', $tdr);

        if ($tdr->state !== TdrState::Approved) {
            throw new WorkflowException('Seul un TDR approuvé peut passer en exécution.');
        }

        return DB::transaction(function () use ($tdr, $user) {
            $tdr->update(['state' => TdrState::InProgress]);
            $tdr->activity->update(['execution_status' => ExecutionStatus::EnCours]);
            $this->record($tdr, $user, WorkflowAction::Started, TdrState::Approved, TdrState::InProgress);

            return $tdr;
        });
    }

    /**
     * Clôture : TDR de fin d'activité, leçons apprises, risques et recommandations.
     *
     * @param  array{lessons: string, risks: ?string, recommendations: ?string}  $report
     */
    public function close(Tdr $tdr, User $user, UploadedFile $finalFile, array $report): Tdr
    {
        Gate::forUser($user)->authorize('execute', $tdr);

        if (! in_array($tdr->state, [TdrState::Approved, TdrState::InProgress], true)) {
            throw new WorkflowException('Seule une activité approuvée ou en exécution peut être clôturée.');
        }

        return DB::transaction(function () use ($tdr, $user, $finalFile, $report) {
            $from = $tdr->state;
            $this->storeDocument($tdr, $user, $finalFile, TdrDocument::KIND_FINAL);

            $tdr->update([
                'state' => TdrState::Closed,
                'closed_at' => now(),
                'lessons' => $report['lessons'],
                'risks' => $report['risks'] ?? null,
                'recommendations' => $report['recommendations'] ?? null,
            ]);
            $tdr->activity->update(['execution_status' => ExecutionStatus::Terminee]);
            $this->record($tdr, $user, WorkflowAction::Closed, $from, TdrState::Closed);

            return $tdr;
        });
    }

    private function storeDocument(Tdr $tdr, User $user, UploadedFile $file, string $kind): TdrDocument
    {
        $version = (int) $tdr->documents()->where('kind', $kind)->max('version') + 1;
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs("tdrs/{$tdr->id}", "{$kind}-v{$version}.{$extension}", self::DISK);

        $document = $tdr->documents()->create([
            'kind' => $kind,
            'version' => $version,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);

        $tdr->unsetRelation('document');

        return $document;
    }

    private function record(
        Tdr $tdr,
        User $user,
        WorkflowAction $action,
        ?TdrState $from,
        TdrState $to,
        ?WorkflowStep $fromStep = null,
        ?WorkflowStep $toStep = null,
        ?string $comment = null,
        ?array $meta = null,
    ): void {
        $tdr->transitions()->create([
            'action' => $action,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'from_step_id' => $fromStep?->id,
            'to_step_id' => $toStep?->id,
            'user_id' => $user->id,
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'meta' => $meta,
        ]);
    }

    private function notifyHandlers(Tdr $tdr, WorkflowStep $step, User $actor): void
    {
        $recipients = $step->handlers($tdr->activity->direction_id)->whereKeyNot($actor->id)->get();

        Notification::send($recipients, new TdrNotification($tdr, TdrNotification::ACTION_REQUIRED, $step->label));
    }

    /** L'auteur et les points focaux de la direction de l'activité. */
    private function notifyOwners(Tdr $tdr, User $actor, string $kind, ?string $comment): void
    {
        /** @var Collection<int, User> $recipients */
        $recipients = User::query()
            ->active()
            ->where(fn ($q) => $q
                ->whereKey($tdr->author_id)
                ->orWhere(fn ($q) => $q->role(Role::PointFocal->value)->where('direction_id', $tdr->activity->direction_id ?? 0)))
            ->whereKeyNot($actor->id)
            ->get();

        Notification::send($recipients, new TdrNotification($tdr, $kind, comment: $comment));
    }
}
