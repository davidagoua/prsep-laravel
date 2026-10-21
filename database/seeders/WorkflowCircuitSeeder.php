<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StepScope;
use App\Enums\Volet;
use App\Enums\WorkflowSubject;
use App\Models\WorkflowCircuit;
use App\Workflow\CircuitPublisher;
use Illuminate\Database\Seeder;

/**
 * Circuits initiaux, repris de l'application Django. Modifiables ensuite dans Administration > Circuits.
 */
class WorkflowCircuitSeeder extends Seeder
{
    public function run(CircuitPublisher $publisher): void
    {
        $circuits = [
            Volet::Projet->value => [
                'name' => 'Validation des TDR — Projet',
                'steps' => [
                    ['label' => 'Direction centrale', 'role' => Role::DirecteurCentral->value, 'scope' => StepScope::Direction->value, 'gives_opinion' => false],
                    ['label' => 'Coordination', 'role' => Role::Coordination->value, 'scope' => StepScope::Global->value, 'gives_opinion' => true],
                ],
            ],
            Volet::Programme->value => [
                'name' => 'Validation des TDR — Programme',
                'steps' => [
                    ['label' => 'Direction centrale', 'role' => Role::DirecteurCentral->value, 'scope' => StepScope::Direction->value, 'gives_opinion' => false],
                    ['label' => 'Comité technique', 'role' => Role::Coordination->value, 'scope' => StepScope::Global->value, 'gives_opinion' => true],
                    ['label' => 'Direction exécutive', 'role' => Role::DirecteurExecutif->value, 'scope' => StepScope::Global->value, 'gives_opinion' => false],
                ],
            ],
        ];

        foreach ($circuits as $volet => $circuit) {
            if (WorkflowCircuit::activeFor(WorkflowSubject::Tdr, Volet::from($volet))) {
                continue;
            }

            $publisher->publish(WorkflowSubject::Tdr, Volet::from($volet), $circuit['name'], $circuit['steps']);
        }
    }
}
