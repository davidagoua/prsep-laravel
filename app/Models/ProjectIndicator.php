<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProjectIndicator extends Model
{
    public const ILD = 'ILD';

    public const HORS_ILD = 'HORS_ILD';

    protected $fillable = ['subcomponent_id', 'code', 'type', 'label', 'baseline', 'target', 'position'];

    public function subcomponent(): BelongsTo
    {
        return $this->belongsTo(ProjectSubcomponent::class, 'subcomponent_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'parent');
    }

    public function isIld(): bool
    {
        return $this->type === self::ILD;
    }
}
