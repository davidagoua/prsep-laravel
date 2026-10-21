<?php

namespace App\Models;

use App\Enums\WorkflowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'action', 'from_state', 'to_state', 'from_step_id', 'to_step_id', 'user_id', 'comment', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'action' => WorkflowAction::class,
            'meta' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
