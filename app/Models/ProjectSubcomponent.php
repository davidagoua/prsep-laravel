<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectSubcomponent extends Model
{
    protected $fillable = ['component_id', 'code', 'sigle', 'label', 'position'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProjectComponent::class, 'component_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ProjectIndicator::class, 'subcomponent_id')->orderBy('position');
    }
}
