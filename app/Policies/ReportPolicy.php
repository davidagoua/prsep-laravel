<?php

namespace App\Policies;

use App\Enums\ReportState;
use App\Enums\Role;
use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        return Report::visibleTo($user)->whereKey($report->id)->exists();
    }

    /** Déposer un rapport pour une direction (ou le rapport consolidé). */
    public function deposit(User $user, ?int $directionId, bool $consolidated = false): bool
    {
        if ($consolidated) {
            return $user->hasAppRole(Role::Coordination);
        }

        return $directionId !== null
            && $user->direction_id === $directionId
            && $user->hasAppRole(Role::PointFocal, Role::DirecteurCentral);
    }

    /** Remplacer le fichier, modifier en ligne, soumettre. */
    public function edit(User $user, Report $report): bool
    {
        if ($report->is_consolidated) {
            return $report->state !== ReportState::Published && $user->hasAppRole(Role::Coordination);
        }

        return $report->state->isEditable() && $this->deposit($user, $report->direction_id);
    }

    /** Valider ou renvoyer un rapport de direction. */
    public function decide(User $user, Report $report): bool
    {
        return ! $report->is_consolidated
            && $report->state === ReportState::Submitted
            && $user->hasAppRole(Role::Coordination);
    }

    public function publish(User $user, Report $report): bool
    {
        return $report->is_consolidated
            && $report->state !== ReportState::Published
            && $user->hasAppRole(Role::Coordination);
    }
}
