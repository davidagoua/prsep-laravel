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
        $a = $this->activity->loadMissing(['direction', 'exercice', 'focalUser', 'category', 'ugp', 'fundingSource']);

        return array_filter([
            'Direction' => $a->direction ? $a->direction->code.($a->direction->name !== $a->direction->code ? ' — '.$a->direction->name : '') : '—',
            'Exercice' => $a->exercice->label,
            'Période' => $a->starts_on ? $a->starts_on->translatedFormat('j M Y').' → '.($a->ends_on?->translatedFormat('j M Y') ?? '…') : null,
            'Point focal' => $a->focalUser?->name,
            'Catégorie de dépense' => $a->category?->label,
            'Type d\'activité' => $a->activity_type,
            'Localisation' => $a->localisation,
            'Partenaire' => $a->partner,
            'Source de financement' => $a->fundingSource?->label,
            'Suivi UGP' => $a->ugp?->label,
            'Procédure d\'acquisition' => $a->procurement_method,
            'ILD en lien' => $a->ild_reference,
            'Références DPPD' => collect([$a->ddp_programme, $a->ddp_action, $a->ddp_activite, $a->ddp_ligne])->filter()->implode(' · ') ?: null,
            'PPM' => $a->ppm_ref,
        ], fn ($v) => $v !== null && $v !== '');
    }

    #[Computed]
    public function hierarchy(): array
    {
        return $this->activity->loadMissing('parent')->hierarchy();
    }

}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('activities.index')" wire:navigate>Activités</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $activity->code ?? 'Activité' }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        @if ($this->hierarchy)
            <ol class="mb-2 flex flex-wrap items-center gap-x-1 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                @foreach ($this->hierarchy as $level)
                    <li class="flex items-center gap-1" title="{{ $level['level'] }} : {{ $level['label'] }}">
                        @if (! $loop->first) <flux:icon.chevron-right variant="micro" class="text-zinc-300" /> @endif
                        <span class="font-medium">{{ $level['code'] }}</span>
                        <span class="max-w-56 truncate">{{ $level['label'] }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
        @if ($activity->parentActivity)
            <div class="mb-2 text-sm">Sous-activité de <a href="{{ route('activities.show', $activity->parentActivity) }}" wire:navigate class="text-prseb-navy-600 hover:underline dark:text-prseb-navy-300">{{ $activity->parentActivity->code }} {{ \Illuminate\Support\Str::limit($activity->parentActivity->label, 80) }}</a></div>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" :color="$activity->volet->color()" variant="outline">{{ $activity->volet->label() }}</flux:badge>
            <flux:badge size="sm" :color="$activity->execution_status->color()">{{ $activity->execution_status->label() }}</flux:badge>
        </div>
        <flux:heading size="xl" level="1" class="mt-2 max-w-4xl">{{ $activity->label }}</flux:heading>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-6">
            <livewire:tdr.panel :activity-id="$activity->id" :key="'tdr-'.$activity->id" />
            <livewire:activities.budget :activity-id="$activity->id" :key="'budget-'.$activity->id" />
            <livewire:finance.activity :activity-id="$activity->id" :key="'finance-'.$activity->id" />
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

            @if ($activity->schedule_months)
                <flux:card class="space-y-3">
                    <flux:heading>Chronogramme</flux:heading>
                    <div class="grid grid-cols-6 gap-1 text-center text-xs">
                        @foreach (['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'] as $i => $m)
                            <span @class([
                                'rounded py-1',
                                'bg-prseb-navy-600 font-semibold text-white' => in_array($i + 1, $activity->schedule_months),
                                'bg-zinc-100 text-zinc-400 dark:bg-zinc-700' => ! in_array($i + 1, $activity->schedule_months),
                            ]) title="{{ ucfirst(now()->setDate(2000, $i + 1, 1)->translatedFormat('F')) }}">{{ $m }}</span>
                        @endforeach
                    </div>
                </flux:card>
            @endif

            @foreach ([['Localités d\'exécution', 'map-pin', 5]] as [$title, $icon, $lot])
                <div class="flex items-center gap-3 rounded-lg border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                    <flux:icon :icon="$icon" class="size-5" />
                    <span class="flex-1">{{ $title }}</span>
                    <flux:badge size="sm" color="zinc">Lot {{ $lot }}</flux:badge>
                </div>
            @endforeach
        </aside>
    </div>
</div>
