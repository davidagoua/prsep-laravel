<?php

namespace App\Enums\Fleet;

enum VehicleStatus: string
{
    case Disponible = 'disponible';
    case EnSortie = 'en_sortie';
    case EnEntretien = 'en_entretien';
    case HorsService = 'hors_service';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::EnSortie => 'En sortie',
            self::EnEntretien => 'En entretien',
            self::HorsService => 'Hors service',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Disponible => 'green',
            self::EnSortie => 'blue',
            self::EnEntretien => 'orange',
            self::HorsService => 'zinc',
        };
    }
}
