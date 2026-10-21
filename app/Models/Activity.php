<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\TdrState;
use App\Enums\Volet;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected $fillable = [
        'volet', 'code', 'label', 'description', 'exercice_id', 'direction_id', 'focal_user_id',
        'starts_on', 'ends_on', 'execution_status',
    ];

    protected function casts(): array
    {
        return [
            'volet' => Volet::class,
            'execution_status' => ExecutionStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function focalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'focal_user_id');
    }

    public function tdrs(): HasMany
    {
        return $this->hasMany(Tdr::class);
    }

    /** Le TDR en vigueur (les dossiers abandonnés sont ignorés). */
    public function tdr(): HasOne
    {
        return $this->hasOne(Tdr::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('state', '!=', TdrState::Abandoned));
    }

    /**
     * Activités visibles : toutes pour les rôles transverses, celles de sa direction sinon.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->seesAllDirections()) {
            return;
        }

        $user->direction_id
            ? $query->where('direction_id', $user->direction_id)
            : $query->whereRaw('1 = 0');
    }
}
