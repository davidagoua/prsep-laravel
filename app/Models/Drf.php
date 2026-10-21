<?php

namespace App\Models;

use App\Enums\DrfStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande de remboursement de fonds : regroupe des paiements à faire rembourser par le bailleur.
 */
class Drf extends Model
{
    protected $table = 'drfs';

    protected $fillable = ['exercice_id', 'number', 'date', 'label', 'status', 'submitted_on', 'reimbursed_on', 'notes', 'user_id'];

    protected $attributes = ['status' => 'brouillon'];

    protected function casts(): array
    {
        return [
            'status' => DrfStatus::class,
            'date' => 'date',
            'submitted_on' => 'date',
            'reimbursed_on' => 'date',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [DrfStatus::Brouillon, DrfStatus::Rejetee], true);
    }

    /** Numéro suivant proposé pour l'exercice : DRF-2026-01, DRF-2026-02… */
    public static function nextNumber(Exercice $exercice): string
    {
        $count = static::where('exercice_id', $exercice->id)->count() + 1;

        do {
            $number = sprintf('DRF-%d-%02d', $exercice->year, $count++);
        } while (static::where('exercice_id', $exercice->id)->where('number', $number)->exists());

        return $number;
    }
}
