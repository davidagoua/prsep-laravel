<?php

namespace App\Models;

use App\Enums\ReportState;
use App\Enums\ReportType;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Report extends Model
{
    protected $fillable = [
        'exercice_id', 'type', 'periode_id', 'direction_id', 'is_consolidated', 'title', 'state',
        'author_id', 'submitted_at', 'accepted_at', 'published_at',
    ];

    protected $attributes = ['state' => 'draft', 'is_consolidated' => false];

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'state' => ReportState::class,
            'is_consolidated' => 'boolean',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ReportDocument::class)->orderByDesc('version');
    }

    public function document(): HasOne
    {
        return $this->hasOne(ReportDocument::class)->ofMany('version', 'max');
    }

    public function audiences(): HasMany
    {
        return $this->hasMany(ReportAudience::class);
    }

    public function transitions(): MorphMany
    {
        return $this->morphMany(WorkflowTransition::class, 'subject')->latest('id');
    }

    /** Intitulé par défaut : « Fiche mensuelle — projet · Mars 2026 · DSPA ». */
    public static function defaultTitle(ReportType $type, ?Periode $periode, ?Direction $direction, bool $consolidated = false): string
    {
        return collect([
            $type->label(),
            $periode?->label,
            $consolidated ? 'Consolidé' : $direction?->code,
        ])->filter()->implode(' · ');
    }

    /**
     * Rapports visibles : les siens (direction), tous pour les rôles transverses,
     * et les rapports consolidés publiés pour leur public.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->seesAllDirections()) {
            return;
        }

        $query->where(function ($q) use ($user) {
            $q->when($user->direction_id && $user->hasAppRole(Role::PointFocal, Role::DirecteurCentral),
                fn ($q) => $q->where('direction_id', $user->direction_id));
            $q->orWhere(fn ($q) => $q->where('state', ReportState::Published)->whereHas('audiences', fn ($q) => $q
                ->whereIn('role', $user->getRoleNames())
                ->orWhere('direction_id', $user->direction_id ?? 0)));
        });
    }
}
