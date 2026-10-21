<?php

namespace Database\Seeders;

use App\Enums\ExerciceStatus;
use App\Models\Exercice;
use Illuminate\Database\Seeder;

class ExerciceSeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;

        $exercice = Exercice::firstOrCreate(['year' => $year], [
            'label' => "Exercice {$year}",
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
            'status' => ExerciceStatus::Ouvert,
        ]);

        $exercice->generatePeriodes();

        if (! Exercice::current()) {
            $exercice->makeCurrent();
        }
    }
}
