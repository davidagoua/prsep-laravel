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
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected $fillable = [
        'volet', 'code', 'import_key', 'label', 'description', 'exercice_id', 'direction_id', 'focal_user_id',
        'parent_type', 'parent_id', 'parent_activity_id', 'starts_on', 'ends_on', 'execution_status',
        'category_id', 'ugp_id', 'funding_source_id', 'activity_type', 'localisation', 'partner',
        'procurement_method', 'ild_reference', 'ddp_programme', 'ddp_nature', 'ddp_action', 'ddp_activite',
        'ddp_ligne', 'ppm_ref', 'schedule_months', 'position',
    ];

    protected function casts(): array
    {
        return [
            'volet' => Volet::class,
            'execution_status' => ExecutionStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'schedule_months' => 'array',
        ];
    }

    /** Indicateur (projet) ou action (programme) de rattachement. */
    public function parent(): MorphTo
    {
        return $this->morphTo();
    }

    public function parentActivity(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_activity_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_activity_id')->orderBy('position');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function ugp(): BelongsTo
    {
        return $this->belongsTo(Ugp::class);
    }

    public function fundingSource(): BelongsTo
    {
        return $this->belongsTo(FundingSource::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('position')->orderBy('id');
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class)->orderByDesc('engaged_on');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_on');
    }

    /**
     * Ajoute les montants planifié, engagé et payé de l'exercice (colonnes planned, engaged, paid).
     */
    public function scopeWithFinancials(Builder $query, int $exerciceId): void
    {
        $query->withSum(['budgetLines as planned' => fn ($q) => $q->where('exercice_id', $exerciceId)], 'amount')
            ->withSum(['engagements as engaged' => fn ($q) => $q->where('exercice_id', $exerciceId)], 'amount')
            ->withSum(['payments as paid' => fn ($q) => $q->where('exercice_id', $exerciceId)], 'amount');
    }

    /**
     * Chemin dans le PTBA, du niveau le plus haut à l'indicateur ou à l'action.
     *
     * @return list<array{level: string, code: ?string, label: string}>
     */
    public function hierarchy(): array
    {
        $parent = $this->parent;

        if ($parent instanceof ProjectIndicator) {
            $sub = $parent->subcomponent;

            return [
                ['level' => 'Composante', 'code' => $sub->component->code, 'label' => $sub->component->label],
                ['level' => 'Sous-composante', 'code' => $sub->sigle ?: $sub->code, 'label' => $sub->label],
                ['level' => $parent->isIld() ? 'ILD' : 'Hors ILD', 'code' => $parent->code, 'label' => $parent->label],
            ];
        }

        if ($parent instanceof ProgrammeAction) {
            $component = $parent->component;
            $subdomain = $component->subdomain;

            return [
                ['level' => 'Domaine', 'code' => $subdomain->domain->code, 'label' => $subdomain->domain->label],
                ['level' => 'Sous-domaine', 'code' => $subdomain->code, 'label' => $subdomain->label],
                ['level' => 'Composante', 'code' => $component->code, 'label' => $component->label],
                ['level' => 'Action', 'code' => $parent->code, 'label' => $parent->label],
            ];
        }

        return [];
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
