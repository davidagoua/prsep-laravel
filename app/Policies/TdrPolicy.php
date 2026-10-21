<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\TdrState;
use App\Models\Tdr;
use App\Models\User;

class TdrPolicy
{
    public function view(User $user, Tdr $tdr): bool
    {
        return $user->can('view', $tdr->activity);
    }

    /** Modifier, remplacer le fichier, soumettre ou abandonner : point focal, tant que le dossier est modifiable. */
    public function edit(User $user, Tdr $tdr): bool
    {
        return $tdr->state->isEditable() && $this->isFocalPointOf($user, $tdr);
    }

    /** Valider ou renvoyer : le valideur de l'étape courante. */
    public function decide(User $user, Tdr $tdr): bool
    {
        return $tdr->state === TdrState::InReview
            && $tdr->currentStep?->canBeHandledBy($user, $tdr->activity->direction_id);
    }

    /** Démarrer puis clôturer l'exécution. */
    public function execute(User $user, Tdr $tdr): bool
    {
        return in_array($tdr->state, [TdrState::Approved, TdrState::InProgress], true)
            && $this->isFocalPointOf($user, $tdr);
    }

    private function isFocalPointOf(User $user, Tdr $tdr): bool
    {
        return $user->hasAppRole(Role::PointFocal)
            && $user->direction_id !== null
            && $user->direction_id === $tdr->activity->direction_id;
    }
}
