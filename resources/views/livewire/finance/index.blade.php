<?php

use App\Enums\Volet;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\ProjectIndicator;
use App\Support\CurrentExercice;
use App\Support\PtbaTree;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Suivi financier')] class extends Component {
    #[Url]
    public string $volet = 'projet';

    #[Url]
    public string $direction = '';

    #[Url]
    public string $ild = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $selectedActivity = null;

    public function mount(): void
    {
        $this->authorize('view-finance');
    }

    public function filters(): array
    {
        return ['direction' => $this->direction ?: null, 'ild' => $this->ild ?: null, 'search' => $this->search ?: null];
    }

    #[Computed]
    public function tree(): array
    {
        return app(PtbaTree::class)->build(
            Volet::tryFrom($this->volet) ?? Volet::Projet,
            app(CurrentExercice::class)->id(),
            auth()->user(),
            $this->filters(),
            fn (Builder $q, int $exerciceId) => $q->withFinancials($exerciceId),
        );
    }

    #[Computed]
    public function kpis(): array
    {
        $t = $this->tree['totals'];
        $planned = $t['planned'] ?? 0;

        return [
            ['Budget planifié', $planned, null, 'banknotes'],
            ['Engagé', $t['engaged'] ?? 0, $planned ? round(($t['engaged'] ?? 0) / $planned * 100) : null, 'document-check'],
            ['Payé', $t['paid'] ?? 0, $planned ? round(($t['paid'] ?? 0) / $planned * 100) : null, 'check-circle'],
            ['Reste à payer', ($t['engaged'] ?? 0) - ($t['paid'] ?? 0), null, 'clock'],
        ];
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code']);
    }

    public function open(int $id): void
    {
        $this->selectedActivity = Activity::visibleTo(auth()->user())->findOrFail($id)->id;
        Flux::modal('finance-flyout')->show();
    }

    #[On('finance-updated')]
    public function refreshTree(): void
    {
        unset($this->tree, $this->kpis);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Suivi financier</flux:heading>
            <flux:text class="mt-1">Planifié, engagé et payé par niveau du PTBA. Cliquez sur une activité pour saisir engagements et paiements.</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="arrow-down-tray" :href="route('finance.export', ['volet' => $volet] + array_filter($this->filters()))">Exporter (Excel)</flux:button>
            <flux:button icon="document-duplicate" :href="route('finance.drf')" wire:navigate>DRF</flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->kpis as [$label, $value, $rate, $icon])
            <flux:card class="flex items-start gap-3">
                <span class="rounded-lg bg-prseb-navy-50 p-2 text-prseb-navy-600 dark:bg-prseb-navy-900/40 dark:text-prseb-navy-300"><flux:icon :icon="$icon" class="size-5" /></span>
                <div class="min-w-0">
                    <flux:subheading>{{ $label }}</flux:subheading>
                    <div class="text-xl font-semibold tabular-nums">{{ number_format($value, 0, ',', ' ') }}</div>
                    @if ($rate !== null)
                        <flux:text size="sm">{{ $rate }} % du planifié</flux:text>
                    @else
                        <flux:text size="sm">FCFA</flux:text>
                    @endif
                </div>
            </flux:card>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:radio.group wire:model.live="volet" variant="segmented" size="sm">
            @foreach (Volet::cases() as $v)
                <flux:radio :value="$v->value" :label="$v->label()" />
            @endforeach
        </flux:radio.group>
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher une activité" class="sm:max-w-xs" clearable />
        <flux:select wire:model.live="direction" class="sm:max-w-48">
            <flux:select.option value="">Toutes les directions</flux:select.option>
            @foreach ($this->directions as $dir)
                <flux:select.option :value="$dir->id">{{ $dir->code }}</flux:select.option>
            @endforeach
        </flux:select>
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

    @php($columns = ['planned', 'engaged', 'paid', 'remaining', 'rate'])
    @if ($this->tree['roots'] === [])
        <flux:card class="py-12 text-center"><flux:text>Aucune activité pour ces filtres dans l'exercice en cours.</flux:text></flux:card>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" role="tree"
            wire:key="ftree-{{ $volet }}-{{ $direction }}-{{ $ild }}-{{ md5($search) }}">
            <div class="min-w-[60rem]">
                <div class="grid items-center gap-3 border-b border-zinc-200 bg-zinc-50 py-2 pe-3 ps-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40"
                    style="grid-template-columns: minmax(0,1fr) repeat(5, 7.5rem)">
                    <span>{{ $this->tree['count'] }} activités</span>
                    <span class="text-end">Planifié</span>
                    <span class="text-end">Engagé</span>
                    <span class="text-end">Payé</span>
                    <span class="text-end">Reste à payer</span>
                    <span class="text-end">Exécution</span>
                </div>
                @foreach ($this->tree['roots'] as $root)
                    <x-ptba.node :node="$root" :columns="$columns" :open="$search ? 9 : 1" select="open" />
                @endforeach
                <x-ptba.metric-row label="Total" :metrics="$this->tree['totals']" :columns="$columns" />
            </div>
        </div>
    @endif

    <flux:modal name="finance-flyout" variant="flyout" class="w-full max-w-3xl">
        @if ($selectedActivity)
            @php($activity = Activity::find($selectedActivity))
            <div class="space-y-4">
                <div>
                    <flux:text size="sm" class="font-mono">{{ $activity->code }}</flux:text>
                    <flux:heading size="lg">{{ $activity->label }}</flux:heading>
                    <flux:link :href="route('activities.show', $activity)" wire:navigate class="text-sm">Ouvrir la fiche activité</flux:link>
                </div>
                <livewire:finance.activity :activity-id="$selectedActivity" :key="'flyout-'.$selectedActivity" />
            </div>
        @endif
    </flux:modal>
</div>
