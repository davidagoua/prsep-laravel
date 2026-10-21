<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProgrammeAction extends Model
{
    protected $fillable = ['component_id', 'code', 'label'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProgrammeComponent::class, 'component_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'parent');
    }
}
