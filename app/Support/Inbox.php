<?php

namespace App\Support;

use App\Enums\ReportState;
use App\Enums\Role;
use App\Enums\TdrState;
use App\Models\Activity;
use App\Models\Report;
use App\Models\ReportObligation;
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

        if ($user->hasAppRole(Role::Coordination)) {
            $sections[] = $this->section('report_decide', 'Rapports à valider', 'Rapports remis par les directions.', 'navy', $this->reportsToValidate()->get());
        }

        if ($user->direction_id && $user->hasAppRole(Role::PointFocal, Role::DirecteurCentral)) {
            $sections[] = $this->section('report_fix', 'Rapports à corriger', 'Rapports renvoyés par la coordination.', 'orange', $this->reportsToFix($user)->get());
            $sections[] = $this->section('report_due', 'Rapports à remettre', 'Échéance dépassée ou dans les 15 prochains jours.', 'zinc', $this->dueObligations($user));
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

        return $count + $this->reportsCount($user);
    }

    /** Rapports demandant une action : à valider (coordination), à corriger ou en retard (direction). */
    public function reportsCount(User $user): int
    {
        $count = $user->hasAppRole(Role::Coordination) ? $this->reportsToValidate()->count() : 0;

        if ($user->direction_id && $user->hasAppRole(Role::PointFocal, Role::DirecteurCentral)) {
            $count += $this->reportsToFix($user)->count();
            $count += $this->dueObligations($user)->filter(fn ($o) => $o->due_on->lt(today()))->count();
        }

        return $count;
    }

    private function reportsToValidate()
    {
        return Report::where('state', ReportState::Submitted)->where('is_consolidated', false)
            ->when($this->exercice->id(), fn ($q, $id) => $q->where('exercice_id', $id))
            ->with(['direction', 'periode'])->orderBy('submitted_at');
    }

    private function reportsToFix(User $user)
    {
        return Report::where('state', ReportState::ChangesRequested)->where('direction_id', $user->direction_id)
            ->with(['direction', 'periode', 'transitions' => fn ($q) => $q->limit(1)])->orderBy('updated_at');
    }

    /** Rapports attendus de la direction, sans rapport remis, en retard ou dus sous 15 jours. */
    private function dueObligations(User $user): Collection
    {
        $obligations = ReportObligation::where('direction_id', $user->direction_id)
            ->when($this->exercice->id(), fn ($q, $id) => $q->where('exercice_id', $id))
            ->whereDate('due_on', '<=', today()->addDays(15))
            ->with(['periode', 'direction'])->orderBy('due_on')->get();

        $delivered = Report::where('direction_id', $user->direction_id)->where('is_consolidated', false)
            ->whereIn('state', [ReportState::Submitted, ReportState::Accepted])
            ->get(['type', 'periode_id'])
            ->map(fn ($r) => $r->type->value.'|'.$r->periode_id)->flip();

        return $obligations->reject(fn ($o) => isset($delivered[$o->type->value.'|'.$o->periode_id]))->values();
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
