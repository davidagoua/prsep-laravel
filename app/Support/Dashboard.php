<?php

namespace App\Support;

use App\Enums\Fleet\VehicleStatus;
use App\Enums\ReportState;
use App\Enums\TdrState;
use App\Enums\Volet;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Fleet\Vehicle;
use App\Models\Fleet\VehicleTrip;
use App\Models\Report;
use App\Models\ReportObligation;
use App\Models\Tdr;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Indicateurs de pilotage d'un exercice : exécution financière, TDR, activités, rapports, parc
 * et comparaison des directions. Toutes les sommes sont calculées en base.
 */
class Dashboard
{
    /** Délai au-delà duquel un TDR en attente est signalé (jours). */
    public const LATE_DAYS = 5;

    public function __construct(private readonly PtbaTree $tree) {}

    public function build(Exercice $exercice, User $user): array
    {
        $activities = Activity::query()->where('exercice_id', $exercice->id)->withFinancials($exercice->id)->get();
        $reports = $this->reports($exercice);

        return [
            'finance' => $this->finance($activities),
            'levels' => $this->levels($exercice, $user),
            'tdr' => $this->tdr($exercice),
            'activities' => $this->activities($activities),
            'reports' => $reports,
            'fleet' => $this->fleet(),
            'directions' => $this->directions($activities, $reports['late_by_direction']),
        ];
    }

    private function finance(Collection $activities): array
    {
        $sum = fn (Collection $items) => [
            'planned' => (int) $items->sum('planned'),
            'engaged' => (int) $items->sum('engaged'),
            'paid' => (int) $items->sum('paid'),
        ];

        return [
            'total' => $sum($activities),
            'volets' => collect(Volet::cases())->mapWithKeys(fn (Volet $v) => [$v->value => $sum($activities->where('volet', $v))])->all(),
        ];
    }

    /** Premiers niveaux du PTBA (composantes du projet, domaines du programme) avec leurs montants. */
    private function levels(Exercice $exercice, User $user): array
    {
        return collect(Volet::cases())->mapWithKeys(function (Volet $volet) use ($exercice, $user) {
            $tree = $this->tree->build($volet, $exercice->id, $user, [], fn (Builder $q, int $id) => $q->withFinancials($id));

            return [$volet->value => collect($tree['roots'])->map(fn ($n) => [
                'code' => $n['code'], 'label' => $n['label'], 'count' => $n['count'] ?? 0,
            ] + $n['metrics'])->all()];
        })->all();
    }

    private function tdr(Exercice $exercice): array
    {
        $tdrs = Tdr::query()->whereHas('activity', fn ($q) => $q->where('exercice_id', $exercice->id));

        $byState = (clone $tdrs)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
        $approved = (clone $tdrs)->whereNotNull('approved_at')->whereNotNull('submitted_at')->get(['submitted_at', 'approved_at']);
        $waiting = (clone $tdrs)->where('state', TdrState::InReview)->with('currentStep')->get();

        return [
            'by_state' => collect(TdrState::cases())
                ->reject(fn ($s) => $s === TdrState::Abandoned)
                ->map(fn ($s) => ['state' => $s, 'count' => (int) ($byState[$s->value] ?? 0)])->values()->all(),
            'in_review' => $waiting->count(),
            'late' => $waiting->filter(fn ($t) => $t->step_entered_at?->lt(now()->subDays(self::LATE_DAYS)))->count(),
            'avg_days' => $approved->isEmpty() ? null : round($approved->avg(fn ($t) => $t->submitted_at->diffInHours($t->approved_at) / 24), 1),
            'by_step' => $waiting->groupBy(fn ($t) => $t->currentStep?->label ?? '—')->map->count()->sortDesc()->all(),
        ];
    }

    private function activities(Collection $activities): array
    {
        $ids = $activities->pluck('id');
        $withLiveTdr = Tdr::whereIn('activity_id', $ids)
            ->whereIn('state', [TdrState::Approved, TdrState::InProgress, TdrState::Closed])
            ->pluck('activity_id')->flip();

        return [
            'total' => $activities->count(),
            'en_cours' => $activities->filter(fn ($a) => $a->execution_status->value === 'en_cours')->count(),
            'terminees' => $activities->filter(fn ($a) => $a->execution_status->value === 'terminee')->count(),
            // Démarrage prévu passé sans TDR approuvé : l'activité prend du retard.
            'a_risque' => $activities->filter(fn ($a) => $a->starts_on?->isPast() && ! isset($withLiveTdr[$a->id]) && $a->execution_status->value === 'a_venir')->count(),
            'sans_direction' => $activities->whereNull('direction_id')->count(),
        ];
    }

    private function reports(Exercice $exercice): array
    {
        $obligations = ReportObligation::where('exercice_id', $exercice->id)->whereDate('due_on', '<', today())->get();
        $delivered = Report::where('exercice_id', $exercice->id)->where('is_consolidated', false)
            ->whereIn('state', [ReportState::Submitted, ReportState::Accepted])
            ->get(['type', 'periode_id', 'direction_id'])
            ->map(fn ($r) => "{$r->type->value}|{$r->periode_id}|{$r->direction_id}")->flip();

        $due = $obligations->count();
        $done = $obligations->filter(fn ($o) => isset($delivered["{$o->type->value}|{$o->periode_id}|{$o->direction_id}"]))->count();

        return [
            'due' => $due,
            'delivered' => $done,
            'late' => $due - $done,
            'rate' => $due ? round($done / $due * 100) : null,
            'to_validate' => Report::where('exercice_id', $exercice->id)->where('state', ReportState::Submitted)->count(),
            'late_by_direction' => $obligations
                ->reject(fn ($o) => isset($delivered["{$o->type->value}|{$o->periode_id}|{$o->direction_id}"]))
                ->countBy('direction_id')->all(),
        ];
    }

    private function fleet(): array
    {
        $byStatus = Vehicle::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'available' => (int) ($byStatus[VehicleStatus::Disponible->value] ?? 0),
            'out' => (int) ($byStatus[VehicleStatus::EnSortie->value] ?? 0),
            'alerts' => VehicleTrip::overdue()->count() + Vehicle::serviceDue()->count(),
        ];
    }

    /** Une ligne par direction ayant des activités : budget, exécution, TDR approuvés, rapports en retard. */
    private function directions(Collection $activities, array $lateReports): array
    {
        $tdrApproved = Tdr::whereIn('activity_id', $activities->pluck('id'))
            ->whereIn('state', [TdrState::Approved, TdrState::InProgress, TdrState::Closed])
            ->pluck('activity_id')->flip();
        $codes = Direction::whereIn('id', $activities->pluck('direction_id')->filter()->unique())->pluck('code', 'id');

        return $activities->whereNotNull('direction_id')->groupBy('direction_id')
            ->map(fn (Collection $items, $id) => [
                'code' => $codes[$id] ?? '?',
                'activities' => $items->count(),
                'planned' => (int) $items->sum('planned'),
                'engaged' => (int) $items->sum('engaged'),
                'paid' => (int) $items->sum('paid'),
                'tdr_approved' => $items->filter(fn ($a) => isset($tdrApproved[$a->id]))->count(),
                'late_reports' => (int) ($lateReports[$id] ?? 0),
            ])
            ->sortByDesc('planned')->values()->all();
    }
}
