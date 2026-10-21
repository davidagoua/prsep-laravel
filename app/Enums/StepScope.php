<?php

namespace App\Enums;

enum StepScope: string
{
    /** Seuls les valideurs de la direction de l'activité. */
    case Direction = 'direction';

    /** Tous les utilisateurs ayant le rôle. */
    case Global = 'global';

    public function label(): string
    {
        return match ($this) {
            self::Direction => 'Direction de l\'activité',
            self::Global => 'Toutes directions',
        };
    }
}
