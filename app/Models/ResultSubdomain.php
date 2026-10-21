<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultSubdomain extends Model
{
    protected $fillable = ['domain_id', 'code', 'label', 'expected_result'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ResultDomain::class, 'domain_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ProgrammeIndicator::class, 'subdomain_id')->orderBy('position');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProgrammeComponent::class, 'subdomain_id')->orderBy('code');
    }
}
