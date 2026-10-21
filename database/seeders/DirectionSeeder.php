<?php

namespace Database\Seeders;

use App\Models\Direction;
use Illuminate\Database\Seeder;

class DirectionSeeder extends Seeder
{
    /**
     * Directions reprises de l'application Django (commande create_departments).
     * Les intitulés complets sont à renseigner depuis l'écran Administration > Directions.
     */
    private const CODES = [
        'DSPA', 'DAAJE', 'DENF', 'DMDA', 'DMOSS', 'DAPS-COGES', 'DESPS', 'DPFC', 'DVSP',
        'CAC-RE', 'SE COP', 'DEEG', 'DAF', 'DELC', 'DAJC', 'DECO', 'DRH', 'DTSI', 'IGENA',
        'DCEP', 'SCRP', 'DEEP', 'SIDI', 'SGP', 'CPPM',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code) {
            Direction::firstOrCreate(['code' => $code], ['name' => $code]);
        }
    }
}
