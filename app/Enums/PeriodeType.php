<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum PeriodeType: string
{
    case Mois = 'mois';
    case Trimestre = 'trimestre';
    case Semestre = 'semestre';
    case Annee = 'annee';

    /** Nombre de mois couverts par une période de ce type. */
    public function months(): int
    {
        return match ($this) {
            self::Mois => 1,
            self::Trimestre => 3,
            self::Semestre => 6,
            self::Annee => 12,
        };
    }

    public function label(CarbonInterface $startsOn, int $number, int $year): string
    {
        return match ($this) {
            self::Mois => ucfirst($startsOn->locale('fr')->translatedFormat('F Y')),
            self::Trimestre => "T{$number} {$year}",
            self::Semestre => "S{$number} {$year}",
            self::Annee => "Exercice {$year}",
        };
    }
}
