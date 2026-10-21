<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case AVenir = 'a_venir';
    case EnCours = 'en_cours';
    case Terminee = 'terminee';
    case Suspendue = 'suspendue';

    public function label(): string
    {
        return match ($this) {
            self::AVenir => 'À venir',
            self::EnCours => 'En cours',
            self::Terminee => 'Terminée',
            self::Suspendue => 'Suspendue',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AVenir => 'zinc',
            self::EnCours => 'blue',
            self::Terminee => 'green',
            self::Suspendue => 'red',
        };
    }
}
