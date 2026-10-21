<?php

namespace App\Enums;

enum Volet: string
{
    case Projet = 'projet';
    case Programme = 'programme';

    public function label(): string
    {
        return match ($this) {
            self::Projet => 'Projet',
            self::Programme => 'Programme',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Projet => 'sky',
            self::Programme => 'violet',
        };
    }
}
