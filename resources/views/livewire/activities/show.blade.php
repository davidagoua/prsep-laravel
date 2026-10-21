<?php

use App\Models\Activity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public Activity $activity;

    public function mount(Activity $activity): void
    {
        $this->authorize('view', $activity);
        $this->activity = $activity;
    }

    public function rendering($view): void
    {
        $view->title(\Illuminate\Support\Str::limit($this->activity->label, 50));
    }

    /** Rafraîchit l'en-tête (statut d'exécution) après une action sur le TDR. */
    #[On('tdr-updated'), On('tdr-decided')]
    public function refreshActivity(): void
    {
        $this->activity->refresh();
    }

    #[Computed]
    public function details(): array
    {
        $a = $this->activity->loadMissing(['direction', 'exercice', 'focalUser']);

        return [
            'Direction' => $a->direction ? $a->direction->code.($a->direction->name !== $a->direction->code ? ' — '.$a->direction->name : '') : '—',
            'Exercice' => $a->exercice->label,
            'Période' => $a->starts_on ? $a->starts_on->translatedFormat('j M Y').' → '.($a->ends_on?->translatedFormat('j M Y') ?? '…') : '—',
            'Point focal' => $a->focalUser?->name ?? '—',
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('activities.index')" wire:navigate>Activités</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $activity->code ?? 'Activité' }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$activity->volet->color()" variant="outline">{{ $activity->volet->label() }}</flux:badge>
            <flux:badge size="sm" :color="$activity->execution_status->color()">{{ $activity->execution_status->label() }}</flux:badge>
        </div>
        <flux:heading size="xl" level="1" class="mt-2 max-w-4xl">{{ $activity->label }}</flux:heading>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_320px]">
        <div class="min-w-0">
            <livewire:tdr.panel :activity-id="$activity->id" :key="'tdr-'.$activity->id" />
        </div>

        <aside class="space-y-4">
            <flux:card class="space-y-3">
                <flux:heading>Informations</flux:heading>
                <dl class="space-y-3 text-sm">
                    @foreach ($this->details as $label => $value)
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                            <dd class="font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($activity->description)
                    <flux:text class="whitespace-pre-line">{{ $activity->description }}</flux:text>
                @endif
            </flux:card>

            @foreach ([['Budget et décaissements', 'banknotes', 1], ['Localités d\'exécution', 'map-pin', 5], ['Rapports', 'document-chart-bar', 4]] as [$title, $icon, $lot])
                <div class="flex items-center gap-3 rounded-lg border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                    <flux:icon :icon="$icon" class="size-5" />
                    <span class="flex-1">{{ $title }}</span>
                    <flux:badge size="sm" color="zinc">Lot {{ $lot }}</flux:badge>
                </div>
            @endforeach
        </aside>
    </div>
</div>
