<?php

namespace App\Enums;

enum ExerciceStatus: string
{
    case EnPreparation = 'en_preparation';
    case Ouvert = 'ouvert';
    case Clos = 'clos';

    public function label(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::Ouvert => 'Ouvert',
            self::Clos => 'Clos',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EnPreparation => 'amber',
            self::Ouvert => 'green',
            self::Clos => 'zinc',
        };
    }
}
