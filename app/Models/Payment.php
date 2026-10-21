<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['activity_id', 'exercice_id', 'engagement_id', 'drf_id', 'amount', 'paid_on', 'reference', 'label', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function drf(): BelongsTo
    {
        return $this->belongsTo(Drf::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Un paiement inclus dans une DRF soumise ou remboursée est figé. */
    public function isLocked(): bool
    {
        return (bool) $this->drf?->status->locksPayments();
    }
}
