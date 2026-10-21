<?php

use App\Imports\Ptba\PtbaAnalyzer;
use App\Jobs\ImportPtba;
use App\Models\PtbaImport;
use App\Support\CurrentExercice;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Title('Import du PTBA')] class extends Component {
    use WithFileUploads;

    public $file;

    #[Url(as: 'import')]
    public ?int $importId = null;

    public string $issueFilter = 'all';

    #[Computed]
    public function exercice()
    {
        return app(CurrentExercice::class)->get();
    }

    #[Computed]
    public function import(): ?PtbaImport
    {
        return $this->importId ? PtbaImport::with('exercice')->find($this->importId) : null;
    }

    #[Computed]
    public function history()
    {
        return PtbaImport::with(['exercice', 'user'])->latest()->limit(10)->get();
    }

    /** Étape 1 → 2 : téléversement puis analyse (aucune écriture dans le PTBA). */
    public function analyze(PtbaAnalyzer $analyzer): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.config('ptba_import.max_size')],
        ], ['file.mimes' => 'Le fichier doit être un classeur Excel (.xlsx).'], ['file' => 'fichier']);

        $path = $this->file->storeAs('imports', Str::uuid().'.xlsx', 'local');
        $parsed = $analyzer->analyze(Storage::disk('local')->path($path), $this->exercice);

        $import = PtbaImport::create([
            'exercice_id' => $this->exercice->id,
            'user_id' => auth()->id(),
            'volet' => in_array($parsed->volet, ['projet', 'programme'], true) ? $parsed->volet : null,
            'format' => $parsed->format,
            'original_name' => $this->file->getClientOriginalName(),
            'path' => $path,
            'status' => PtbaImport::ANALYSED,
            'issues' => $parsed->issues,
            'summary' => $parsed->hasErrors() && $parsed->activities === [] ? null : $analyzer->summarize($parsed, $this->exercice),
        ]);
        Storage::disk('local')->put($import->parsedPath(), json_encode($parsed->toArray()));

        $this->reset('file');
        $this->importId = $import->id;
        unset($this->import, $this->history);
    }

    /** Étape 3 → 4 : enregistrement en file d'attente, tout ou rien. */
    public function confirm(): void
    {
        $import = $this->import;
        if (! $import || $import->status !== PtbaImport::ANALYSED || $import->errorCount() > 0) {
            Flux::toast('Cet import ne peut pas être validé.', variant: 'danger');

            return;
        }

        $import->update(['status' => PtbaImport::QUEUED]);
        ImportPtba::dispatch($import);
        unset($this->import, $this->history);
    }

    public function cancel(): void
    {
        if ($this->import?->status === PtbaImport::ANALYSED) {
            Storage::disk('local')->delete([$this->import->path, $this->import->parsedPath()]);
            $this->import->update(['status' => PtbaImport::CANCELLED]);
        }
        $this->restart();
    }

    public function restart(): void
    {
        $this->reset('importId', 'file', 'issueFilter');
        unset($this->import, $this->history);
    }

    public function open(int $id): void
    {
        $this->importId = $id;
        unset($this->import);
    }

    public function refreshProgress(): void
    {
        unset($this->import, $this->history);
    }

    /** Nœuds de premier et deuxième niveaux pour l'aperçu. */
    public function previewTree(): array
    {
        $tree = collect($this->import?->summary['tree'] ?? []);
        $children = $tree->groupBy('parent');

        return $tree->whereNull('parent')->map(fn ($root) => $root + [
            'children' => ($children[$root['key']] ?? collect())->values()->all(),
        ])->values()->all();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Import du PTBA</flux:heading>
            <flux:text class="mt-1 max-w-3xl">
                Importez le PTBA de l'exercice {{ $this->exercice?->label }} depuis Excel : modèle SIGPRO ou format historique (projet ou programme).
                Rien n'est enregistré avant votre validation ; un réimport met à jour les activités existantes.
            </flux:text>
        </div>
        <flux:dropdown align="end">
            <flux:button icon="arrow-down-tray" icon-trailing="chevron-down">Modèles Excel</flux:button>
            <flux:menu>
                <flux:menu.item :href="route('ptba.template', 'projet')" icon="table-cells">Modèle PTBA projet</flux:menu.item>
                <flux:menu.item :href="route('ptba.template', 'programme')" icon="table-cells">Modèle PTBA programme</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- Étapes --}}
    @php
        $import = $this->import;
        $step = match (true) {
            ! $import => 1,
            $import->status === PtbaImport::ANALYSED => 2,
            default => 4,
        };
    @endphp
    <ol class="flex flex-wrap gap-2 text-sm">
        @foreach ([1 => 'Téléverser', 2 => 'Contrôler et prévisualiser', 4 => 'Importer'] as $n => $label)
            <li @class([
                'flex items-center gap-2 rounded-full px-3 py-1',
                'bg-prseb-navy-600 text-white' => $step === $n,
                'bg-prseb-green-50 text-prseb-green-700 dark:bg-prseb-green-700/20 dark:text-prseb-green-500' => $step > $n,
                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => $step < $n,
            ])>
                @if ($step > $n) <flux:icon.check variant="micro" /> @endif
                {{ $label }}
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <flux:card class="max-w-2xl space-y-5">
            <form wire:submit="analyze" class="space-y-4">
                <flux:input type="file" wire:model="file" label="Fichier Excel (.xlsx)" accept=".xlsx"
                    description="Le format est détecté automatiquement. 20 Mo maximum." />
                <div wire:loading wire:target="file" class="text-sm text-zinc-500">Téléversement…</div>
                <div class="flex items-center justify-end gap-3">
                    <span wire:loading wire:target="analyze" class="text-sm text-zinc-500">Analyse du fichier, quelques secondes…</span>
                    <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled">Analyser</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($import && $step === 2)
        @php($summary = $import->summary)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:subheading>Fichier</flux:subheading>
                <div class="mt-1 truncate font-medium" title="{{ $import->original_name }}">{{ $import->original_name }}</div>
                <flux:text size="sm">{{ $summary['format_label'] ?? 'Format non reconnu' }}{{ isset($summary['year']) ? ' · '.$summary['year'] : '' }}</flux:text>
            </flux:card>
            <flux:card>
                <flux:subheading>Activités</flux:subheading>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($summary['activities'] ?? 0, 0, ',', ' ') }}</div>
                <flux:text size="sm">{{ $summary['new_activities'] ?? 0 }} nouvelles · {{ $summary['updated_activities'] ?? 0 }} mises à jour</flux:text>
            </flux:card>
            <flux:card>
                <flux:subheading>Budget planifié</flux:subheading>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($summary['total'] ?? 0, 0, ',', ' ') }}</div>
                <flux:text size="sm">FCFA · {{ $summary['lines'] ?? 0 }} lignes de coût</flux:text>
            </flux:card>
            <flux:card>
                <flux:subheading>Contrôles</flux:subheading>
                <div class="mt-1 flex items-center gap-3 text-2xl font-semibold tabular-nums">
                    <span @class(['text-red-600' => $import->errorCount(), 'text-prseb-green-600' => ! $import->errorCount()])>{{ $import->errorCount() }}</span>
                    <span class="text-base font-normal text-zinc-500">erreur(s)</span>
                    <span class="text-prseb-orange-500">{{ $import->warningCount() }}</span>
                    <span class="text-base font-normal text-zinc-500">avert.</span>
                </div>
                @if ($import->issues)
                    <flux:link :href="route('ptba.report', $import)" class="text-sm">Télécharger le rapport (CSV)</flux:link>
                @endif
            </flux:card>
        </div>

        @if (isset($summary['year']) && $summary['year'] !== $import->exercice->year)
            <flux:callout color="amber" icon="exclamation-triangle"
                heading="Le fichier porte sur {{ $summary['year'] }}, il sera importé dans l'{{ $import->exercice->label }}." />
        @endif

        <div class="grid gap-6 xl:grid-cols-2">
            {{-- Anomalies --}}
            <flux:card class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading>Anomalies</flux:heading>
                    <flux:radio.group wire:model.live="issueFilter" variant="segmented" size="sm">
                        <flux:radio value="all" label="Toutes" />
                        <flux:radio value="error" label="Erreurs" />
                        <flux:radio value="warning" label="Avertissements" />
                    </flux:radio.group>
                </div>
                @php($issues = collect($import->issues)->when($issueFilter !== 'all', fn ($c) => $c->where('level', $issueFilter))->sortBy(fn ($i) => $i['level'] === 'error' ? 0 : 1)->values())
                @if ($issues->isEmpty())
                    <flux:text>Aucune anomalie.</flux:text>
                @else
                    <ul class="max-h-[28rem] divide-y divide-zinc-100 overflow-y-auto text-sm dark:divide-zinc-700">
                        @foreach ($issues->take(200) as $issue)
                            <li class="flex gap-2 py-2">
                                <flux:icon :icon="$issue['level'] === 'error' ? 'x-circle' : 'exclamation-triangle'" variant="mini"
                                    class="mt-0.5 size-4 shrink-0 {{ $issue['level'] === 'error' ? 'text-red-500' : 'text-prseb-orange-500' }}" />
                                <div>
                                    @if ($issue['sheet'] || $issue['row'])
                                        <span class="text-xs text-zinc-500">{{ $issue['sheet'] }}{{ $issue['row'] ? ' · ligne '.$issue['row'] : '' }}</span><br>
                                    @endif
                                    {{ $issue['message'] }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($issues->count() > 200)
                        <flux:text size="sm">… et {{ $issues->count() - 200 }} autres dans le rapport CSV.</flux:text>
                    @endif
                @endif
            </flux:card>

            {{-- Aperçu --}}
            <flux:card class="space-y-3">
                <flux:heading>Aperçu du PTBA</flux:heading>
                <ul class="max-h-[28rem] space-y-1 overflow-y-auto text-sm">
                    @foreach ($this->previewTree() as $root)
                        <li>
                            <details open class="group">
                                <summary class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                                    <flux:icon.chevron-right variant="micro" class="transition group-open:rotate-90" />
                                    <span class="font-mono text-xs text-zinc-500">{{ $root['code'] }}</span>
                                    <span class="flex-1 truncate font-medium">{{ $root['label'] }}</span>
                                    <span class="tabular-nums">{{ number_format($root['amount'], 0, ',', ' ') }}</span>
                                </summary>
                                <ul class="ms-6 border-s border-zinc-200 ps-2 dark:border-zinc-700">
                                    @foreach ($root['children'] as $child)
                                        <li class="flex items-center gap-2 px-2 py-1">
                                            <span class="font-mono text-xs text-zinc-500">{{ $child['code'] }}</span>
                                            <span class="flex-1 truncate">{{ $child['label'] }}</span>
                                            <span class="text-xs text-zinc-500">{{ $child['activities'] }} act.</span>
                                            <span class="w-32 text-end tabular-nums">{{ number_format($child['amount'], 0, ',', ' ') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($import->errorCount())
                <flux:text class="text-red-600">Corrigez les erreurs dans le fichier puis analysez-le de nouveau.</flux:text>
            @elseif ($summary['updated_activities'] ?? 0)
                <flux:text size="sm">Les lignes budgétaires des {{ $summary['updated_activities'] }} activités déjà présentes seront remplacées.</flux:text>
            @endif
            <flux:button variant="ghost" wire:click="cancel">Abandonner</flux:button>
            <flux:button variant="primary" icon="check" wire:click="confirm" :disabled="$import->errorCount() > 0"
                wire:confirm="Importer {{ $summary['activities'] ?? 0 }} activités dans l'{{ $import->exercice->label }} ?">
                Valider l'import
            </flux:button>
        </div>
    @endif

    @if ($import && $step === 4)
        @if (in_array($import->status, [PtbaImport::QUEUED, PtbaImport::RUNNING]))
            <div wire:poll.2s="refreshProgress" class="hidden"></div>
        @endif
        <flux:card class="max-w-2xl space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ $import->original_name }}</flux:heading>
                <flux:badge :color="$import->statusColor()">{{ $import->statusLabel() }}</flux:badge>
            </div>

            @if (in_array($import->status, [PtbaImport::QUEUED, PtbaImport::RUNNING]))
                <div>
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-full rounded-full bg-accent transition-all" style="width: {{ max(3, $import->progress) }}%"></div>
                    </div>
                    <flux:text size="sm" class="mt-1">{{ $import->progress }} % — vous pouvez quitter cette page, l'import continue.</flux:text>
                </div>
            @elseif ($import->status === PtbaImport::DONE)
                @php($result = $import->summary['result'] ?? [])
                <flux:callout color="green" icon="check-circle" heading="PTBA importé">
                    <flux:callout.text>
                        {{ $result['created'] ?? 0 }} activités créées, {{ $result['updated'] ?? 0 }} mises à jour, {{ $result['lines'] ?? 0 }} lignes de coût.
                    </flux:callout.text>
                </flux:callout>
                <div class="flex flex-wrap gap-2">
                    <flux:button variant="primary" :href="route('activities.tree', ['volet' => $import->volet?->value])" wire:navigate icon="rectangle-group">Voir l'arborescence</flux:button>
                    <flux:button :href="route('activities.index')" wire:navigate>Liste des activités</flux:button>
                    <flux:button variant="ghost" wire:click="restart">Nouvel import</flux:button>
                </div>
            @elseif ($import->status === PtbaImport::FAILED)
                <flux:callout color="red" icon="x-circle" :heading="$import->error" />
                <flux:button wire:click="restart">Recommencer</flux:button>
            @else
                <flux:button wire:click="restart">Nouvel import</flux:button>
            @endif
        </flux:card>
    @endif

    {{-- Historique --}}
    @if ($this->history->isNotEmpty())
        <div>
            <flux:heading class="mb-2">Derniers imports</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Fichier</flux:table.column>
                    <flux:table.column>Exercice</flux:table.column>
                    <flux:table.column>Statut</flux:table.column>
                    <flux:table.column align="end">Activités</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->history as $row)
                        <flux:table.row :key="$row->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $row->created_at->translatedFormat('j M Y, H:i') }}<div class="text-xs text-zinc-500">{{ $row->user?->name }}</div></flux:table.cell>
                            <flux:table.cell class="max-w-64 truncate">{{ $row->original_name }}<div class="text-xs text-zinc-500">{{ PtbaAnalyzer::FORMATS[$row->format] ?? $row->format }}</div></flux:table.cell>
                            <flux:table.cell>{{ $row->exercice->label }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$row->statusColor()">{{ $row->statusLabel() }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $row->summary['activities'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="xs" variant="ghost" wire:click="open({{ $row->id }})">Ouvrir</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</div>
