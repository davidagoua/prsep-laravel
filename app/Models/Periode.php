<?php

namespace App\Models;

use App\Enums\PeriodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Periode extends Model
{
    protected $fillable = ['exercice_id', 'type', 'number', 'label', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'type' => PeriodeType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }
}
