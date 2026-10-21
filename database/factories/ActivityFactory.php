<?php

namespace Database\Factories;

use App\Enums\ExecutionStatus;
use App\Enums\Volet;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'volet' => Volet::Projet,
            'code' => strtoupper(fake()->bothify('A#.#.##')),
            'label' => fake()->sentence(8),
            'exercice_id' => Exercice::factory(),
            'direction_id' => Direction::factory(),
            'starts_on' => now()->startOfMonth(),
            'ends_on' => now()->addMonths(2)->endOfMonth(),
            'execution_status' => ExecutionStatus::AVenir,
        ];
    }

    public function programme(): static
    {
        return $this->state(['volet' => Volet::Programme]);
    }
}
