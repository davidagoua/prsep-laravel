<?php

namespace App\Models;

use App\Enums\ExerciceStatus;
use App\Enums\PeriodeType;
use Database\Factories\ExerciceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Exercice extends Model
{
    /** @use HasFactory<ExerciceFactory> */
    use HasFactory;

    protected $fillable = ['year', 'label', 'starts_on', 'ends_on', 'budget_total', 'status', 'is_current'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget_total' => 'integer',
            'status' => ExerciceStatus::class,
            'is_current' => 'boolean',
        ];
    }

    public function periodes(): HasMany
    {
        return $this->hasMany(Periode::class)->orderBy('starts_on')->orderBy('type');
    }

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first();
    }

    /**
     * Désigne cet exercice comme exercice courant par défaut pour tous les utilisateurs.
     */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            static::query()->where('is_current', true)->update(['is_current' => false]);
            $this->forceFill(['is_current' => true])->save();
        });
    }

    /**
     * Crée les mois, trimestres, semestres et l'année de l'exercice.
     * Idempotent : les périodes existantes sont conservées.
     */
    public function generatePeriodes(): void
    {
        $monthCount = (int) $this->starts_on->copy()->startOfMonth()->diffInMonths($this->ends_on->copy()->startOfMonth()) + 1;

        foreach (PeriodeType::cases() as $type) {
            $span = $type->months();

            for ($index = 0; $index * $span < $monthCount; $index++) {
                $startsOn = $this->starts_on->copy()->startOfMonth()->addMonths($index * $span);
                $endsOn = $startsOn->copy()->addMonths($span)->subDay();
                $number = $index + 1;

                $this->periodes()->firstOrCreate(
                    ['type' => $type, 'number' => $number],
                    [
                        'label' => $type->label($startsOn, $number, $this->year),
                        'starts_on' => $startsOn,
                        'ends_on' => $endsOn->min($this->ends_on),
                    ],
                );
            }
        }
    }
}
