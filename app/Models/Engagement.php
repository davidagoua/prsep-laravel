<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Engagement extends Model
{
    protected $fillable = ['activity_id', 'exercice_id', 'amount', 'engaged_on', 'reference', 'supplier', 'label', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'engaged_on' => 'date'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Montant restant à payer sur cet engagement (en excluant éventuellement un paiement en cours de modification). */
    public function remaining(?int $exceptPaymentId = null): int
    {
        return $this->amount - (int) $this->payments()->when($exceptPaymentId, fn ($q) => $q->whereKeyNot($exceptPaymentId))->sum('amount');
    }
}
