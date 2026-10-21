<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $fillable = [
        'activity_id', 'exercice_id', 'label', 'unit_id', 'quantity', 'frequency', 'duration',
        'unit_cost', 'economic_nature', 'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'frequency' => 'float',
            'duration' => 'float',
            'unit_cost' => 'integer',
            'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BudgetLine $line) {
            $line->amount = static::computeAmount($line->quantity, $line->frequency, $line->duration, $line->unit_cost);
        });
    }

    public static function computeAmount(float|int|null $quantity, float|int|null $frequency, float|int|null $duration, float|int|null $unitCost): int
    {
        return (int) round(($quantity ?? 0) * ($frequency ?? 1) * ($duration ?? 1) * ($unitCost ?? 0));
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
