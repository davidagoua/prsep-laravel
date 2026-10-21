<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\StepScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    protected $fillable = ['circuit_id', 'position', 'label', 'role', 'scope', 'gives_opinion'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'scope' => StepScope::class,
            'gives_opinion' => 'boolean',
        ];
    }

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(WorkflowCircuit::class, 'circuit_id');
    }

    /** L'utilisateur peut-il décider à cette étape pour une activité de cette direction ? */
    public function canBeHandledBy(User $user, ?int $directionId): bool
    {
        if (! $user->is_active || ! $user->hasAppRole($this->role)) {
            return false;
        }

        return $this->scope === StepScope::Global || ($directionId !== null && $user->direction_id === $directionId);
    }

    /** Les valideurs à notifier quand un dossier arrive à cette étape. */
    public function handlers(?int $directionId): Builder
    {
        return User::query()
            ->active()
            ->role($this->role->value)
            ->when($this->scope === StepScope::Direction, fn ($q) => $q->where('direction_id', $directionId ?? 0));
    }
}
