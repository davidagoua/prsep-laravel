<?php

namespace App\Workflow;

use App\Enums\Role;
use App\Enums\StepScope;
use App\Enums\Volet;
use App\Enums\WorkflowSubject;
use App\Models\User;
use App\Models\WorkflowCircuit;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les étapes d'un circuit. Un circuit déjà utilisé par un dossier n'est jamais modifié :
 * on publie une nouvelle version, et les dossiers en cours terminent sur l'ancienne.
 */
class CircuitPublisher
{
    /**
     * @param  list<array{label: string, role: string, scope: string, gives_opinion: bool}>  $steps
     */
    public function publish(WorkflowSubject $subject, ?Volet $volet, string $name, array $steps, ?User $user = null): WorkflowCircuit
    {
        if ($steps === []) {
            throw new WorkflowException('Un circuit doit comporter au moins une étape.');
        }

        return DB::transaction(function () use ($subject, $volet, $name, $steps, $user) {
            $current = WorkflowCircuit::activeFor($subject, $volet);

            if ($current && ! $current->isUsed()) {
                $circuit = $current;
                $circuit->update(['name' => $name]);
                $circuit->steps()->delete();
            } else {
                $current?->update(['is_active' => false]);

                $version = (int) WorkflowCircuit::where('subject', $subject)->where('volet', $volet)->max('version') + 1;
                $circuit = WorkflowCircuit::create([
                    'subject' => $subject,
                    'volet' => $volet,
                    'name' => $name,
                    'version' => $version,
                    'is_active' => true,
                    'created_by' => $user?->id,
                ]);
            }

            foreach (array_values($steps) as $index => $step) {
                $circuit->steps()->create([
                    'position' => $index + 1,
                    'label' => trim($step['label']),
                    'role' => Role::from($step['role']),
                    'scope' => StepScope::from($step['scope']),
                    'gives_opinion' => (bool) $step['gives_opinion'],
                ]);
            }

            return $circuit->load('steps');
        });
    }
}
