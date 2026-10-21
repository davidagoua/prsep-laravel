<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgrammeComponent extends Model
{
    protected $fillable = ['subdomain_id', 'code', 'label'];

    public function subdomain(): BelongsTo
    {
        return $this->belongsTo(ResultSubdomain::class, 'subdomain_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProgrammeAction::class, 'component_id')->orderBy('code');
    }
}
