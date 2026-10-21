<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultDomain extends Model
{
    protected $fillable = ['code', 'label'];

    public function subdomains(): HasMany
    {
        return $this->hasMany(ResultSubdomain::class, 'domain_id')->orderBy('code');
    }
}
