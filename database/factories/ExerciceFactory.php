<?php

namespace Database\Factories;

use App\Enums\ExerciceStatus;
use App\Models\Exercice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercice>
 */
class ExerciceFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2020, 2040);

        return [
            'year' => $year,
            'label' => "Exercice {$year}",
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
            'budget_total' => 0,
            'status' => ExerciceStatus::Ouvert,
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(['is_current' => true]);
    }
}
