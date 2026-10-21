<?php

use App\Enums\ReportState;
use App\Enums\ReportType;
use App\Models\Periode;
use App\Models\Report;
use App\Models\ReportObligation;
use App\Reporting\ReportWorkflow;
use App\Workflow\WorkflowException;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    #[Url]
    public string $type = '';

    #[Url]
    public ?int $periode = null;

    public $file;

    public function mount(): void
    {
        abort_unless(auth()->user()->seesAllDirections(), 403);
        abort_unless(ReportType::tryFrom($this->type)?->isPeriodic() && $this->periodeModel, 404);
    }

    public function rendering($view): void
    {
        $view->title('Consolidation · '.$this->periodeModel->label);
    }

    #[Computed]
    public function periodeModel(): ?Periode
    {
        return $this->periode ? Periode::with('exercice')->find($this->periode) : null;
    }

    #[Computed]
    public function rows()
    {
        $type = ReportType::from($this->type);
        $reports = Report::where('periode_id', $this->periode)->where('type', $type)->where('is_consolidated', false)
            ->with('document')->get()->keyBy('direction_id');

        return ReportObligation::where('periode_id', $this->periode)->where('type', $type)
            ->with('direction')->get()->sortBy('direction.code')
            ->map(fn ($o) => ['obligation' => $o, 'report' => $reports[$o->direction_id] ?? null])->values();
    }

    #[Computed]
    public function consolidated(): ?Report
    {
        return Report::where('periode_id', $this->periode)->where('type', $this->type)->where('is_consolidated', true)->first();
    }

    public function createConsolidated(ReportWorkflow $workflow): void
    {
        $this->authorize('manage-reports');
        $this->validate(['file' => ['required', 'file', 'mimes:docx,doc,odt,pdf,xlsx,xls,ods,pptx', 'max:20480']], attributes: ['file' => 'fichier']);

        try {
            $report = $workflow->create($this->periodeModel->exercice, ReportType::from($this->type), $this->periodeModel, null, true, auth()->user(), $this->file);
        } catch (WorkflowException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->redirectRoute('reports.show', $report, navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    @php($type = ReportType::from($type))
    @php($delivered = $this->rows->filter(fn ($r) => $r['report']?->state->isDelivered())->count())
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('reports.index', ['vue' => 'calendrier', 'type' => $type->value])" wire:navigate>Rapports</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $this->periodeModel->label }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1">{{ $type->label() }} · {{ $this->periodeModel->label }}</flux:heading>
        <flux:text class="mt-1">{{ $delivered }} rapport(s) remis sur {{ $this->rows->count() }} attendu(s).</flux:text>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Direction</flux:table.column>
                <flux:table.column>Échéance</flux:table.column>
                <flux:table.column>État</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->rows as ['obligation' => $o, 'report' => $report])
                    @php($status = ReportObligation::status($report, $o))
                    <flux:table.row :key="$o->id">
                        <flux:table.cell class="font-medium">{{ $o->direction->code }}</flux:table.cell>
                        <flux:table.cell>{{ $o->due_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge></flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($report)
                                <div class="flex justify-end gap-1">
                                    @if ($report->document)
                                        <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('report-documents.show', $report->document)" aria-label="Télécharger" />
                                    @endif
                                    <flux:button size="xs" :variant="$report->state === ReportState::Submitted ? 'primary' : 'ghost'" :href="route('reports.show', $report)" wire:navigate>
                                        {{ $report->state === ReportState::Submitted ? 'Valider' : 'Ouvrir' }}
                                    </flux:button>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="py-8 text-center">Aucun rapport attendu pour cette période.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:card class="space-y-4 self-start">
            <flux:heading>Rapport consolidé</flux:heading>
            @if ($c = $this->consolidated)
                <div class="flex items-center gap-2">
                    <flux:badge :color="$c->state->color()">{{ $c->state->label() }}</flux:badge>
                    <flux:link :href="route('reports.show', $c)" wire:navigate>{{ $c->title }}</flux:link>
                </div>
            @else
                <flux:text size="sm">Rassemblez les rapports validés des directions dans un document unique, puis publiez-le aux destinataires choisis.</flux:text>
                @can('manage-reports')
                    <form wire:submit="createConsolidated" class="space-y-3">
                        <flux:input type="file" wire:model="file" label="Document consolidé" accept=".docx,.doc,.odt,.pdf,.xlsx,.xls,.ods,.pptx" />
                        <flux:button type="submit" variant="primary" icon="arrow-up-tray" class="w-full">Déposer le rapport consolidé</flux:button>
                    </form>
                @endcan
            @endif
        </flux:card>
    </div>
</div>
