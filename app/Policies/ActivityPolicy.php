<?php

namespace App\Policies;

use App\Enums\ExerciceStatus;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    public function view(User $user, Activity $activity): bool
    {
        return $user->seesAllDirections()
            || ($user->direction_id !== null && $user->direction_id === $activity->direction_id
                && $user->hasAppRole(Role::PointFocal, Role::DirecteurCentral));
    }

    public function create(User $user): bool
    {
        return $user->hasAppRole(Role::PointFocal) && $user->direction_id !== null;
    }

    /** Budget : point focal de la direction ou coordination, tant que l'exercice n'est pas clos. */
    public function editBudget(User $user, Activity $activity): bool
    {
        if ($activity->exercice?->status === ExerciceStatus::Clos) {
            return false;
        }

        return $user->hasAppRole(Role::Coordination)
            || ($user->hasAppRole(Role::PointFocal) && $user->direction_id !== null && $user->direction_id === $activity->direction_id);
    }

    /** Seul un point focal de la direction de l'activité dépose le TDR. */
    public function depositTdr(User $user, Activity $activity): bool
    {
        return $user->hasAppRole(Role::PointFocal)
            && $user->direction_id !== null
            && $user->direction_id === $activity->direction_id;
    }
}
