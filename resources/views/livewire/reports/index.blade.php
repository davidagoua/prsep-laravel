<?php

use App\Enums\ReportState;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Models\Direction;
use App\Models\Periode;
use App\Models\Report;
use App\Models\ReportObligation;
use App\Reporting\ObligationGenerator;
use App\Reporting\ReportWorkflow;
use App\Support\CurrentExercice;
use App\Workflow\WorkflowException;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Title('Rapports')] class extends Component {
    use WithFileUploads;

    #[Url]
    public string $vue = '';

    #[Url]
    public string $type = 'mensuel_projet';

    #[Url]
    public string $etat = '';

    // Dépôt
    public string $d_type = 'mensuel_projet';
    public ?string $d_periode = null;
    public string $d_title = '';
    public $d_file;

    // Rapports attendus
    /** @var list<string> */
    public array $g_types = [];
    /** @var list<string> */
    public array $g_directions = [];
    public int $g_delay = 10;
    public string $g_from = '';

    public function mount(): void
    {
        $this->vue = $this->vue ?: ($this->ownsDirection() ? 'remettre' : 'calendrier');
    }

    private function ownsDirection(): bool
    {
        return auth()->user()->direction_id && auth()->user()->hasAppRole(Role::PointFocal, Role::DirecteurCentral);
    }

    #[Computed]
    public function exercice()
    {
        return app(CurrentExercice::class)->get();
    }

    /** Rapports attendus de ma direction, avec leur rapport éventuel. */
    #[Computed]
    public function myObligations()
    {
        $user = auth()->user();
        $obligations = ReportObligation::where('exercice_id', $this->exercice->id)
            ->where('direction_id', $user->direction_id ?? 0)
            ->with('periode')->orderBy('due_on')->get();
        $reports = $this->reportsFor($obligations->pluck('direction_id')->unique()->all());

        return $obligations->map(fn ($o) => ['obligation' => $o, 'report' => $reports[$this->key($o)] ?? null]);
    }

    #[Computed]
    public function calendar(): array
    {
        $type = ReportType::tryFrom($this->type) ?? ReportType::MensuelProjet;
        $periodes = $this->exercice->periodes()->where('type', $type->periodeType())->orderBy('number')->get();
        $obligations = ReportObligation::where('exercice_id', $this->exercice->id)->where('type', $type)
            ->when(! auth()->user()->seesAllDirections(), fn ($q) => $q->where('direction_id', auth()->user()->direction_id ?? 0))
            ->with('direction')->get();
        $reports = $this->reportsFor($obligations->pluck('direction_id')->unique()->all(), $type);

        $rows = $obligations->groupBy('direction_id')->map(fn ($items) => [
            'direction' => $items->first()->direction,
            'cells' => $items->keyBy('periode_id')->map(fn ($o) => ['obligation' => $o, 'report' => $reports[$this->key($o)] ?? null]),
        ])->sortBy(fn ($row) => $row['direction']->code)->values();

        return ['type' => $type, 'periodes' => $periodes, 'rows' => $rows];
    }

    #[Computed]
    public function reports()
    {
        return Report::visibleTo(auth()->user())
            ->where('exercice_id', $this->exercice->id)
            ->when($this->etat, fn ($q) => $q->where('state', $this->etat))
            ->with(['periode', 'direction'])
            ->latest('updated_at')->limit(100)->get();
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function depositPeriodes()
    {
        $type = ReportType::tryFrom($this->d_type);

        return $type?->isPeriodic() ? $this->exercice->periodes()->where('type', $type->periodeType())->orderBy('number')->get() : collect();
    }

    private function key(ReportObligation $o): string
    {
        return "{$o->type->value}|{$o->periode_id}|{$o->direction_id}";
    }

    /** @return array<string, Report> rapports de direction indexés par type, période et direction */
    private function reportsFor(array $directionIds, ?ReportType $type = null): array
    {
        return Report::where('exercice_id', $this->exercice->id)->where('is_consolidated', false)
            ->whereIn('direction_id', $directionIds)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('id')->get()
            ->keyBy(fn ($r) => "{$r->type->value}|{$r->periode_id}|{$r->direction_id}")
            ->all();
    }

    public function openDeposit(?string $type = null, ?int $periode = null): void
    {
        $this->reset('d_title', 'd_file');
        $this->d_type = $type ?? ReportType::Circonstancie->value;
        $this->d_periode = $periode ? (string) $periode : null;
        $this->resetValidation();
        Flux::modal('report-deposit')->show();
    }

    public function deposit(ReportWorkflow $workflow): void
    {
        $type = ReportType::from($this->d_type);
        $this->validate([
            'd_type' => ['required'],
            'd_periode' => [$type->isPeriodic() ? 'required' : 'nullable'],
            'd_title' => [$type->isPeriodic() ? 'nullable' : 'required', 'string', 'max:255'],
            'd_file' => ['required', 'file', 'mimes:docx,doc,odt,pdf,xlsx,xls,ods,pptx', 'max:20480'],
        ], [
            'd_periode.required' => 'Choisissez la période.',
            'd_title.required' => 'Donnez un intitulé au rapport circonstancié.',
        ], ['d_file' => 'fichier']);

        try {
            $report = $workflow->create(
                $this->exercice, $type,
                $this->d_periode ? Periode::find($this->d_periode) : null,
                auth()->user()->direction, false, auth()->user(), $this->d_file, $this->d_title ?: null,
            );
        } catch (WorkflowException $e) {
            $this->addError('d_periode', $e->getMessage());

            return;
        } catch (AuthorizationException) {
            $this->addError('d_file', 'Seuls le point focal et le directeur d\'une direction déposent ses rapports.');

            return;
        }

        $this->redirectRoute('reports.show', $report, navigate: true);
    }

    public function openGenerator(): void
    {
        $this->authorize('manage-reports');
        $this->g_types = [ReportType::MensuelProjet->value, ReportType::TrimestrielProjet->value];
        $this->g_directions = $this->directions->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->g_from = today()->startOfMonth()->max($this->exercice->starts_on)->toDateString();
        Flux::modal('report-obligations')->show();
    }

    public function generate(ObligationGenerator $generator): void
    {
        $this->authorize('manage-reports');
        $this->validate([
            'g_types' => ['required', 'array', 'min:1'],
            'g_directions' => ['required', 'array', 'min:1'],
            'g_delay' => ['required', 'integer', 'between:0,60'],
            'g_from' => ['nullable', 'date'],
        ], ['g_types.required' => 'Choisissez au moins un type.', 'g_directions.required' => 'Choisissez au moins une direction.']);

        $count = $generator->generate(
            $this->exercice,
            array_map(fn ($t) => ReportType::from($t), $this->g_types),
            array_map('intval', $this->g_directions),
            $this->g_delay,
            $this->g_from ? \Illuminate\Support\Carbon::parse($this->g_from) : null,
        );

        Flux::modal('report-obligations')->close();
        Flux::toast("{$count} rapport(s) attendu(s) ajouté(s).", variant: 'success');
        unset($this->calendar, $this->myObligations);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Rapports</flux:heading>
            <flux:text class="mt-1">Rapports attendus des directions pour l'{{ $this->exercice->label }}, dépôt, validation par la coordination et consolidation.</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('manage-reports')
                <flux:button icon="calendar-days" wire:click="openGenerator">Rapports attendus</flux:button>
            @endcan
            @if (auth()->user()->direction_id && auth()->user()->hasAppRole(Role::PointFocal, Role::DirecteurCentral))
                <flux:button variant="primary" icon="plus" wire:click="openDeposit">Rapport circonstancié</flux:button>
            @endif
        </div>
    </div>

    <flux:radio.group wire:model.live="vue" variant="segmented" size="sm" class="self-start">
        @if (auth()->user()->direction_id && auth()->user()->hasAppRole(Role::PointFocal, Role::DirecteurCentral))
            <flux:radio value="remettre" label="À remettre" />
        @endif
        <flux:radio value="calendrier" label="Calendrier" />
        <flux:radio value="liste" label="Tous les rapports" />
    </flux:radio.group>

    @if ($vue === 'remettre')
        <flux:card class="p-0!">
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->myObligations as ['obligation' => $o, 'report' => $report])
                    @php($status = ReportObligation::status($report, $o))
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="ob-{{ $o->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $o->type->label() }} · {{ $o->periode->label }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">À remettre avant le {{ $o->due_on->translatedFormat('j F Y') }}{{ ! $report && $o->due_on->isFuture() ? ' ('.$o->due_on->diffForHumans().')' : '' }}</div>
                        </div>
                        <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                        @if ($report)
                            <flux:button size="sm" :href="route('reports.show', $report)" wire:navigate>Ouvrir</flux:button>
                        @else
                            <flux:button size="sm" variant="primary" icon="arrow-up-tray" wire:click="openDeposit('{{ $o->type->value }}', {{ $o->periode_id }})">Déposer</flux:button>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-10 text-center"><flux:text>Aucun rapport attendu pour votre direction sur cet exercice.</flux:text></li>
                @endforelse
            </ul>
        </flux:card>
    @elseif ($vue === 'calendrier')
        @php($cal = $this->calendar)
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="type" class="sm:max-w-72">
                @foreach (ReportType::periodic() as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex flex-wrap gap-2 text-xs">
                @foreach ([['zinc', 'Attendu'], ['red', 'En retard'], ['blue', 'Soumis'], ['orange', 'À corriger'], ['green', 'Validé']] as [$c, $l])
                    <flux:badge size="sm" :color="$c">{{ $l }}</flux:badge>
                @endforeach
            </div>
        </div>

        @if ($cal['rows']->isEmpty())
            <flux:card class="py-10 text-center">
                <flux:text>Aucun rapport attendu de ce type.</flux:text>
                @can('manage-reports')
                    <flux:button class="mt-3" wire:click="openGenerator">Définir les rapports attendus</flux:button>
                @endcan
            </flux:card>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/40">
                            <th class="sticky start-0 bg-zinc-50 px-3 py-2 text-start text-xs font-medium uppercase text-zinc-500 dark:bg-zinc-900">Direction</th>
                            @foreach ($cal['periodes'] as $p)
                                <th class="px-2 py-2 text-center text-xs font-medium whitespace-nowrap">
                                    @if (auth()->user()->seesAllDirections())
                                        <a href="{{ route('reports.period', ['type' => $cal['type']->value, 'periode' => $p->id]) }}" wire:navigate class="text-prseb-navy-600 hover:underline dark:text-prseb-navy-300" title="Consolidation">{{ $p->label }}</a>
                                    @else
                                        {{ $p->label }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach ($cal['rows'] as $row)
                            <tr wire:key="row-{{ $row['direction']->id }}">
                                <td class="sticky start-0 bg-white px-3 py-2 font-medium dark:bg-zinc-800">{{ $row['direction']->code }}</td>
                                @foreach ($cal['periodes'] as $p)
                                    <td class="px-1 py-1.5 text-center">
                                        @if ($cell = $row['cells'][$p->id] ?? null)
                                            @php($status = ReportObligation::status($cell['report'], $cell['obligation']))
                                            @if ($cell['report'])
                                                <a href="{{ route('reports.show', $cell['report']) }}" wire:navigate title="{{ $status['label'] }}">
                                                    <flux:badge size="sm" :color="$status['color']" class="cursor-pointer">{{ $status['label'] }}</flux:badge>
                                                </a>
                                            @else
                                                <flux:badge size="sm" :color="$status['color']" variant="outline" title="Échéance : {{ $cell['obligation']->due_on->format('d/m/Y') }}">{{ $status['label'] }}</flux:badge>
                                            @endif
                                        @else
                                            <span class="text-zinc-300">·</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <div class="flex flex-wrap gap-3">
            <flux:select wire:model.live="etat" class="sm:max-w-48">
                <flux:select.option value="">Tous les états</flux:select.option>
                @foreach (ReportState::cases() as $s)
                    <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Rapport</flux:table.column>
                <flux:table.column>Direction</flux:table.column>
                <flux:table.column>État</flux:table.column>
                <flux:table.column>Mis à jour</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->reports as $report)
                    <flux:table.row :key="$report->id">
                        <flux:table.cell class="max-w-xl whitespace-normal!">
                            <a href="{{ route('reports.show', $report) }}" wire:navigate class="font-medium hover:text-prseb-navy-600 hover:underline dark:hover:text-prseb-navy-300">{{ $report->title }}</a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $report->is_consolidated ? 'Consolidé' : $report->direction?->code }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$report->state->color()">{{ $report->state->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $report->updated_at->diffForHumans() }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="py-8 text-center">Aucun rapport.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="report-deposit" class="w-full max-w-lg">
        <form wire:submit="deposit" class="space-y-4">
            <flux:heading size="lg">Déposer un rapport</flux:heading>
            <flux:select wire:model.live="d_type" label="Type">
                @foreach (ReportType::cases() as $t)
                    <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($this->depositPeriodes->isNotEmpty())
                <flux:select wire:model="d_periode" label="Période" placeholder="Choisir">
                    @foreach ($this->depositPeriodes as $p)
                        <flux:select.option :value="$p->id">{{ $p->label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input wire:model="d_title" label="Intitulé" :placeholder="ReportType::from($d_type)->isPeriodic() ? 'Facultatif : généré à partir du type et de la période' : 'Ex. Rapport sur l\'incident de la DRENA de Bouaké'" />
            <flux:input type="file" wire:model="d_file" label="Fichier" description="Word, OpenDocument, Excel ou PDF. Les formats bureautiques se modifient ensuite en ligne." accept=".docx,.doc,.odt,.pdf,.xlsx,.xls,.ods,.pptx" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray">Déposer</flux:button>
            </div>
        </form>
    </flux:modal>

    @can('manage-reports')
        <flux:modal name="report-obligations" class="w-full max-w-2xl">
            <form wire:submit="generate" class="space-y-5">
                <div>
                    <flux:heading size="lg">Rapports attendus</flux:heading>
                    <flux:text class="mt-1">Crée, pour l'{{ $this->exercice->label }}, un rapport attendu par type, période et direction. Les rapports attendus déjà définis sont conservés.</flux:text>
                </div>
                <flux:checkbox.group wire:model="g_types" label="Types de rapports">
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (ReportType::periodic() as $t)
                            <flux:checkbox :value="$t->value" :label="$t->label()" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
                <flux:checkbox.group wire:model="g_directions" label="Directions concernées">
                    <div class="grid max-h-48 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-5">
                        @foreach ($this->directions as $d)
                            <flux:checkbox :value="(string) $d->id" :label="$d->code" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="g_delay" type="number" min="0" max="60" label="Délai de remise (jours après la fin de la période)" />
                    <flux:input wire:model="g_from" type="date" label="À partir de" description="Les périodes déjà terminées avant cette date sont ignorées." />
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Créer</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</div>
