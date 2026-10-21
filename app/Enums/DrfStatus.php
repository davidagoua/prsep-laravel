<?php

namespace App\Enums;

enum DrfStatus: string
{
    case Brouillon = 'brouillon';
    case Soumise = 'soumise';
    case Remboursee = 'remboursee';
    case Rejetee = 'rejetee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise au bailleur',
            self::Remboursee => 'Remboursée',
            self::Rejetee => 'Rejetée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Brouillon => 'zinc',
            self::Soumise => 'blue',
            self::Remboursee => 'green',
            self::Rejetee => 'red',
        };
    }

    /** Les paiements d'une DRF soumise ou remboursée ne peuvent plus être modifiés. */
    public function locksPayments(): bool
    {
        return in_array($this, [self::Soumise, self::Remboursee], true);
    }
}
