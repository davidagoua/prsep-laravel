<?php

namespace App\Policies;

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

    /** Seul un point focal de la direction de l'activité dépose le TDR. */
    public function depositTdr(User $user, Activity $activity): bool
    {
        return $user->hasAppRole(Role::PointFocal)
            && $user->direction_id !== null
            && $user->direction_id === $activity->direction_id;
    }
}
