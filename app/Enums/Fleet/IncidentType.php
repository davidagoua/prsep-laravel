<?php

namespace App\Enums\Fleet;

enum IncidentType: string
{
    case PanneSeche = 'panne_seche';
    case Crevaison = 'crevaison';
    case PieceRetiree = 'piece_retiree';
    case Accident = 'accident';
    case Panne = 'panne';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::PanneSeche => 'Panne sèche',
            self::Crevaison => 'Crevaison',
            self::PieceRetiree => 'Pièce retirée',
            self::Accident => 'Accident',
            self::Panne => 'Panne mécanique',
            self::Autre => 'Autre',
        };
    }
}
