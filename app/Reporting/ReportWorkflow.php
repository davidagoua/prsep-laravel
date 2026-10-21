<?php

namespace App\Reporting;

use App\Enums\ReportState;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Enums\WorkflowAction;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Periode;
use App\Models\Report;
use App\Models\ReportDocument;
use App\Models\User;
use App\Notifications\ReportNotification;
use App\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Rapports des directions : brouillon → soumis → validé (ou renvoyé avec commentaire) ;
 * rapport consolidé de la coordination : brouillon → publié à un public choisi.
 */
class ReportWorkflow
{
    public const DISK = 'local';

    public function create(Exercice $exercice, ReportType $type, ?Periode $periode, ?Direction $direction, bool $consolidated, User $user, UploadedFile $file, ?string $title = null): Report
    {
        Gate::forUser($user)->authorize('deposit', [Report::class, $direction?->id, $consolidated]);

        if ($type->isPeriodic() && ! $periode) {
            throw new WorkflowException('Choisissez la période du rapport.');
        }

        if ($type->isPeriodic() && $this->existing($exercice, $type, $periode, $direction, $consolidated)) {
            throw new WorkflowException('Un rapport existe déjà pour cette période : déposez plutôt une nouvelle version.');
        }

        return DB::transaction(function () use ($exercice, $type, $periode, $direction, $consolidated, $user, $file, $title) {
            $report = Report::create([
                'exercice_id' => $exercice->id,
                'type' => $type,
                'periode_id' => $periode?->id,
                'direction_id' => $consolidated ? null : $direction?->id,
                'is_consolidated' => $consolidated,
                'title' => $title ?: Report::defaultTitle($type, $periode, $direction, $consolidated),
                'author_id' => $user->id,
            ]);

            $this->storeDocument($report, $user, $file);
            $this->record($report, $user, WorkflowAction::Created, null, ReportState::Draft);

            return $report;
        });
    }

    public function replaceDocument(Report $report, User $user, UploadedFile $file, ?string $comment = null): ReportDocument
    {
        Gate::forUser($user)->authorize('edit', $report);

        return DB::transaction(function () use ($report, $user, $file, $comment) {
            $document = $this->storeDocument($report, $user, $file);
            $this->record($report, $user, WorkflowAction::NewVersion, $report->state, $report->state, $comment, ['version' => $document->version]);

            return $document;
        });
    }

    /** Enregistrement depuis l'éditeur en ligne : la dernière version est mise à jour sur place. */
    public function saveOnline(ReportDocument $document, User $user, string $contents): void
    {
        Gate::forUser($user)->authorize('edit', $document->report);

        Storage::disk($document->disk)->put($document->path, $contents);
        $document->forceFill(['size' => strlen($contents), 'uploaded_by' => $user->id])->save();
        $document->touch();
    }

    public function submit(Report $report, User $user, ?string $comment = null): Report
    {
        Gate::forUser($user)->authorize('edit', $report);

        if ($report->is_consolidated) {
            throw new WorkflowException('Le rapport consolidé se publie directement.');
        }

        return DB::transaction(function () use ($report, $user, $comment) {
            $from = $report->state;
            $report->update(['state' => ReportState::Submitted, 'submitted_at' => now()]);
            $this->record($report, $user, $from === ReportState::ChangesRequested ? WorkflowAction::Resubmitted : WorkflowAction::Submitted, $from, ReportState::Submitted, $comment);

            $coordination = User::active()->role(Role::Coordination->value)->whereKeyNot($user->id)->get();
            Notification::send($coordination, new ReportNotification($report, ReportNotification::SUBMITTED, $comment));

            return $report;
        });
    }

    public function requestChanges(Report $report, User $user, string $comment): Report
    {
        Gate::forUser($user)->authorize('decide', $report);

        if (trim($comment) === '') {
            throw new WorkflowException('Expliquez ce qui doit être corrigé.');
        }

        return DB::transaction(function () use ($report, $user, $comment) {
            $report->update(['state' => ReportState::ChangesRequested]);
            $this->record($report, $user, WorkflowAction::ChangesRequested, ReportState::Submitted, ReportState::ChangesRequested, $comment);
            $this->notifyDirection($report, $user, ReportNotification::RETURNED, $comment);

            return $report;
        });
    }

    public function accept(Report $report, User $user, ?string $comment = null): Report
    {
        Gate::forUser($user)->authorize('decide', $report);

        return DB::transaction(function () use ($report, $user, $comment) {
            $report->update(['state' => ReportState::Accepted, 'accepted_at' => now()]);
            $this->record($report, $user, WorkflowAction::Approved, ReportState::Submitted, ReportState::Accepted, $comment);
            $this->notifyDirection($report, $user, ReportNotification::ACCEPTED, $comment);

            return $report;
        });
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $directionIds
     */
    public function publish(Report $report, User $user, array $roles, array $directionIds): Report
    {
        Gate::forUser($user)->authorize('publish', $report);

        if ($roles === [] && $directionIds === []) {
            throw new WorkflowException('Choisissez au moins un rôle ou une direction destinataire.');
        }

        return DB::transaction(function () use ($report, $user, $roles, $directionIds) {
            $from = $report->state;
            $report->audiences()->delete();
            foreach ($roles as $role) {
                $report->audiences()->create(['role' => $role]);
            }
            foreach ($directionIds as $id) {
                $report->audiences()->create(['direction_id' => $id]);
            }
            $report->update(['state' => ReportState::Published, 'published_at' => now()]);
            $this->record($report, $user, WorkflowAction::Published, $from, ReportState::Published, null, ['roles' => $roles, 'directions' => $directionIds]);

            $audience = User::active()->whereKeyNot($user->id)
                ->where(fn ($q) => $q->when($roles, fn ($q) => $q->role($roles))->when($directionIds, fn ($q) => $q->orWhereIn('direction_id', $directionIds)))
                ->get();
            Notification::send($audience, new ReportNotification($report, ReportNotification::PUBLISHED));

            return $report;
        });
    }

    private function existing(Exercice $exercice, ReportType $type, ?Periode $periode, ?Direction $direction, bool $consolidated): bool
    {
        return Report::where('exercice_id', $exercice->id)
            ->where('type', $type)
            ->where('periode_id', $periode?->id)
            ->where('is_consolidated', $consolidated)
            ->when(! $consolidated, fn ($q) => $q->where('direction_id', $direction?->id))
            ->exists();
    }

    private function storeDocument(Report $report, User $user, UploadedFile $file): ReportDocument
    {
        $version = (int) $report->documents()->max('version') + 1;
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs("reports/{$report->id}", "v{$version}.{$extension}", self::DISK);

        $document = $report->documents()->create([
            'version' => $version,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
        $report->unsetRelation('document');

        return $document;
    }

    private function record(Report $report, User $user, WorkflowAction $action, ?ReportState $from, ReportState $to, ?string $comment = null, ?array $meta = null): void
    {
        $report->transitions()->create([
            'action' => $action,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'user_id' => $user->id,
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'meta' => $meta,
        ]);
    }

    /** L'auteur et les points focaux / directeurs de la direction du rapport. */
    private function notifyDirection(Report $report, User $actor, string $kind, ?string $comment): void
    {
        $recipients = User::active()
            ->where(fn ($q) => $q->whereKey($report->author_id)
                ->orWhere(fn ($q) => $q->where('direction_id', $report->direction_id ?? 0)->role([Role::PointFocal->value, Role::DirecteurCentral->value])))
            ->whereKeyNot($actor->id)
            ->get();

        Notification::send($recipients, new ReportNotification($report, $kind, $comment));
    }
}
