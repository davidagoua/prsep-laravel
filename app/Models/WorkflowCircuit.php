<?php

namespace App\Models;

use App\Enums\Volet;
use App\Enums\WorkflowSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowCircuit extends Model
{
    protected $fillable = ['subject', 'volet', 'name', 'version', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'subject' => WorkflowSubject::class,
            'volet' => Volet::class,
            'is_active' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'circuit_id')->orderBy('position');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public static function activeFor(WorkflowSubject $subject, ?Volet $volet): ?self
    {
        return static::active()
            ->where('subject', $subject)
            ->where('volet', $volet)
            ->with('steps')
            ->latest('version')
            ->first();
    }

    public function firstStep(): ?WorkflowStep
    {
        return $this->steps->first();
    }

    public function stepAfter(WorkflowStep $step): ?WorkflowStep
    {
        return $this->steps->first(fn (WorkflowStep $s) => $s->position > $step->position);
    }

    /** Le circuit est-il déjà utilisé par au moins un dossier ? */
    public function isUsed(): bool
    {
        return Tdr::where('circuit_id', $this->id)->exists();
    }
}
