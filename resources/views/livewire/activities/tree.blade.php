<?php

use App\Enums\Volet;
use App\Models\Direction;
use App\Models\ProjectIndicator;
use App\Support\CurrentExercice;
use App\Support\PtbaTree;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Arborescence du PTBA')] class extends Component {
    #[Url]
    public string $volet = 'projet';

    #[Url]
    public string $direction = '';

    #[Url]
    public string $ild = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Computed]
    public function tree(): array
    {
        return app(PtbaTree::class)->build(
            Volet::tryFrom($this->volet) ?? Volet::Projet,
            app(CurrentExercice::class)->id(),
            auth()->user(),
            ['direction' => $this->direction ?: null, 'ild' => $this->ild ?: null, 'search' => $this->search ?: null],
        );
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code']);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Activités</flux:heading>
            <flux:text class="mt-1">PTBA de l'exercice en cours, du niveau le plus haut jusqu'aux activités, avec le budget planifié.</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('import-ptba')
                <flux:button icon="arrow-up-tray" :href="route('ptba.import')" wire:navigate>Importer le PTBA</flux:button>
            @endcan
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:radio.group variant="segmented" size="sm">
            <flux:radio value="liste" label="Liste" x-on:click="Livewire.navigate('{{ route('activities.index') }}')" />
            <flux:radio value="arbre" label="Arborescence" checked />
        </flux:radio.group>
        <flux:radio.group wire:model.live="volet" variant="segmented" size="sm">
            @foreach (Volet::cases() as $v)
                <flux:radio :value="$v->value" :label="$v->label()" />
            @endforeach
        </flux:radio.group>
    </div>

    <div class="grid gap-3 sm:flex sm:flex-wrap">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher une activité" class="sm:max-w-xs" clearable />
        @if (auth()->user()->seesAllDirections())
            <flux:select wire:model.live="direction" class="sm:max-w-48">
                <flux:select.option value="">Toutes les directions</flux:select.option>
                @foreach ($this->directions as $dir)
                    <flux:select.option :value="$dir->id">{{ $dir->code }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        @if ($volet === 'projet')
            <flux:select wire:model.live="ild" class="sm:max-w-40">
                <flux:select.option value="">ILD et hors ILD</flux:select.option>
                <flux:select.option :value="ProjectIndicator::ILD">ILD</flux:select.option>
                <flux:select.option :value="ProjectIndicator::HORS_ILD">Hors ILD</flux:select.option>
            </flux:select>
        @endif
        <div class="flex gap-1 sm:ms-auto">
            <flux:button size="sm" variant="ghost" icon="chevron-double-down" x-on:click="$dispatch('ptba-toggle-all', true)">Tout déplier</flux:button>
            <flux:button size="sm" variant="ghost" icon="chevron-double-up" x-on:click="$dispatch('ptba-toggle-all', false)">Tout replier</flux:button>
        </div>
    </div>

    @if ($this->tree['roots'] === [])
        <flux:card class="flex flex-col items-center gap-2 py-12 text-center">
            <flux:icon.rectangle-group class="size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:heading>Aucune activité pour ce volet</flux:heading>
            <flux:text class="max-w-md">Le PTBA de cet exercice n'a pas encore été importé, ou aucune activité ne correspond aux filtres.</flux:text>
            @can('import-ptba')
                <flux:button class="mt-2" variant="primary" :href="route('ptba.import')" wire:navigate>Importer le PTBA</flux:button>
            @endcan
        </flux:card>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" role="tree" wire:key="tree-{{ $volet }}-{{ $direction }}-{{ $ild }}-{{ md5($search) }}">
            <div class="min-w-[44rem]">
                <div class="grid items-center gap-3 border-b border-zinc-200 bg-zinc-50 py-2 pe-3 ps-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40"
                    style="grid-template-columns: minmax(0,1fr) 9rem">
                    <span>{{ $this->tree['count'] }} activités</span>
                    <span class="text-end">Planifié (FCFA)</span>
                </div>
                @foreach ($this->tree['roots'] as $root)
                    <x-ptba.node :node="$root" :open="$search ? 9 : 1" />
                @endforeach
                <div class="grid items-center gap-3 py-2.5 pe-3 ps-2 text-sm font-semibold" style="grid-template-columns: minmax(0,1fr) 9rem">
                    <span>Total</span>
                    <span class="text-end tabular-nums">{{ number_format($this->tree['totals']['planned'], 0, ',', ' ') }}</span>
                </div>
            </div>
        </div>
    @endif
</div>
