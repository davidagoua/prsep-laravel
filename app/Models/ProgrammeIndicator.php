<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammeIndicator extends Model
{
    protected $fillable = ['subdomain_id', 'position', 'label', 'baseline', 'target'];

    public function subdomain(): BelongsTo
    {
        return $this->belongsTo(ResultSubdomain::class, 'subdomain_id');
    }
}
