<?php

namespace App\Models;

use App\Enums\StepScope;
use App\Enums\TdrState;
use App\Enums\WorkflowSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Tdr extends Model
{
    protected $fillable = [
        'activity_id', 'author_id', 'title', 'state', 'circuit_id', 'current_step_id', 'returned_from_step_id',
        'coordination_granted', 'injunction', 'lessons', 'risks', 'recommendations',
        'submitted_at', 'step_entered_at', 'approved_at', 'closed_at',
    ];

    protected $attributes = [
        'state' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'state' => TdrState::class,
            'coordination_granted' => 'boolean',
            'injunction' => 'boolean',
            'submitted_at' => 'datetime',
            'step_entered_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(WorkflowCircuit::class, 'circuit_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    public function returnedFromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'returned_from_step_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TdrDocument::class)->orderByDesc('version');
    }

    public function document(): HasOne
    {
        return $this->hasOne(TdrDocument::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('kind', TdrDocument::KIND_TDR));
    }

    public function finalDocument(): HasOne
    {
        return $this->hasOne(TdrDocument::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('kind', TdrDocument::KIND_FINAL));
    }

    public function transitions(): MorphMany
    {
        return $this->morphMany(WorkflowTransition::class, 'subject')->latest('id');
    }

    /** Le circuit suivi par le dossier, ou celui qu'il suivra une fois soumis. */
    public function effectiveCircuit(): ?WorkflowCircuit
    {
        return $this->circuit ?? WorkflowCircuit::activeFor(WorkflowSubject::Tdr, $this->activity->volet);
    }

    /**
     * Dossiers en attente d'une décision de cet utilisateur (son rôle à l'étape courante,
     * et sa direction si l'étape est limitée à la direction de l'activité).
     */
    public function scopeAwaitingDecisionBy(Builder $query, User $user): void
    {
        $roles = $user->getRoleNames()->all();

        $query->where('state', TdrState::InReview)
            ->whereHas('currentStep', fn ($q) => $q->whereIn('role', $roles))
            ->where(function ($q) use ($user) {
                $q->whereHas('currentStep', fn ($q) => $q->where('scope', StepScope::Global))
                    ->orWhere(fn ($q) => $q
                        ->whereHas('currentStep', fn ($q) => $q->where('scope', StepScope::Direction))
                        ->whereHas('activity', fn ($q) => $q->where('direction_id', $user->direction_id ?? 0)));
            });
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('activity', fn ($q) => $q->visibleTo($user));
    }

    /**
     * Étapes du circuit avec leur statut pour l'affichage (fait, en cours, renvoyé, à venir).
     *
     * @return list<array{step: WorkflowStep, status: string}>
     */
    public function stepper(): array
    {
        $circuit = $this->effectiveCircuit();
        if (! $circuit) {
            return [];
        }

        $reference = $this->currentStep ?? $this->returnedFromStep;
        $allDone = in_array($this->state, [TdrState::Approved, TdrState::InProgress, TdrState::Closed], true);

        return $circuit->steps->map(function (WorkflowStep $step) use ($reference, $allDone) {
            $status = match (true) {
                $allDone => 'done',
                $reference === null => 'upcoming',
                $step->position < $reference->position => 'done',
                $step->is($reference) => $this->state === TdrState::ChangesRequested ? 'returned' : 'current',
                default => 'upcoming',
            };

            return ['step' => $step, 'status' => $status];
        })->all();
    }
}
