<?php

namespace App\Enums;

enum WorkflowSubject: string
{
    case Tdr = 'tdr';

    public function label(): string
    {
        return match ($this) {
            self::Tdr => 'Termes de référence (TDR)',
        };
    }
}
