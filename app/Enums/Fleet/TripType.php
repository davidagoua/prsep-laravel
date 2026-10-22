<?php

namespace App\Enums\Fleet;

enum TripType: string
{
    case Mission = 'mission';
    case Course = 'course';
    case Deplacement = 'deplacement';

    public function label(): string
    {
        return match ($this) {
            self::Mission => 'Mission',
            self::Course => 'Course',
            self::Deplacement => 'Déplacement',
        };
    }
}
