<?php

namespace App\Support;

use App\Enums\Role;
use App\Enums\TdrState;
use App\Models\Activity;
use App\Models\Tdr;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * « À traiter » : ce qui attend une action de l'utilisateur, regroupé par nature.
 */
class Inbox
{
    public function __construct(private readonly CurrentExercice $exercice) {}

    /**
     * @return list<array{key: string, title: string, description: string, tone: string, items: Collection}>
     */
    public function sections(User $user): array
    {
        $sections = [];

        $toDecide = Tdr::awaitingDecisionBy($user)
            ->with(['activity.direction', 'currentStep'])
            ->orderBy('step_entered_at')
            ->get();
        if ($toDecide->isNotEmpty()) {
            $sections[] = $this->section('decide', 'À valider', 'TDR qui attendent votre décision.', 'navy', $toDecide);
        }

        if ($user->hasAppRole(Role::PointFocal) && $user->direction_id) {
            $own = fn (TdrState $state) => Tdr::query()
                ->where('state', $state)
                ->whereHas('activity', fn ($q) => $q->where('direction_id', $user->direction_id))
                ->with(['activity', 'returnedFromStep', 'transitions' => fn ($q) => $q->limit(1)])
                ->orderBy('updated_at')
                ->get();

            $sections[] = $this->section('fix', 'À corriger', 'TDR renvoyés : corrigez puis resoumettez.', 'orange', $own(TdrState::ChangesRequested));
            $sections[] = $this->section('submit', 'Brouillons à soumettre', 'TDR déposés mais pas encore envoyés en validation.', 'zinc', $own(TdrState::Draft));
            $sections[] = $this->section('start', 'Approuvés, à démarrer', 'Le circuit est terminé : lancez l\'exécution.', 'green', $own(TdrState::Approved));
            $sections[] = $this->section('close', 'En exécution', 'Clôturez l\'activité une fois terminée (TDR de fin d\'activité).', 'sky', $own(TdrState::InProgress));
            $sections[] = $this->section('prepare', 'TDR à préparer', 'Activités qui démarrent dans les 30 prochains jours sans TDR.', 'zinc', $this->activitiesWithoutTdr($user));
        }

        return array_values(array_filter($sections, fn ($s) => $s['items']->isNotEmpty()));
    }

    /** Nombre d'éléments urgents (décisions attendues et corrections demandées), pour les badges du menu. */
    public function urgentCount(User $user): int
    {
        $count = Tdr::awaitingDecisionBy($user)->count();

        if ($user->hasAppRole(Role::PointFocal) && $user->direction_id) {
            $count += Tdr::where('state', TdrState::ChangesRequested)
                ->whereHas('activity', fn ($q) => $q->where('direction_id', $user->direction_id))
                ->count();
        }

        return $count;
    }

    private function activitiesWithoutTdr(User $user): Collection
    {
        return Activity::query()
            ->where('direction_id', $user->direction_id)
            ->when($this->exercice->id(), fn ($q, $id) => $q->where('exercice_id', $id))
            ->whereDoesntHave('tdrs', fn ($q) => $q->where('state', '!=', TdrState::Abandoned))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', today()->addDays(30)))
            ->orderBy('starts_on')
            ->limit(20)
            ->get();
    }

    private function section(string $key, string $title, string $description, string $tone, Collection $items): array
    {
        return compact('key', 'title', 'description', 'tone', 'items');
    }
}
