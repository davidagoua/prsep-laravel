<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAudience extends Model
{
    public $timestamps = false;

    protected $fillable = ['role', 'direction_id'];

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function label(): string
    {
        return $this->role ? (Role::tryFrom($this->role)?->label() ?? $this->role) : (string) $this->direction?->code;
    }
}
