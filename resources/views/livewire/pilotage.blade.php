<?php

use App\Enums\Volet;
use App\Support\CurrentExercice;
use App\Support\Dashboard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Tableau de bord')] class extends Component {
    #[Url]
    public string $volet = 'projet';

    public function mount(): void
    {
        $this->authorize('view-finance');
    }

    #[Computed]
    public function data(): array
    {
        return app(Dashboard::class)->build(app(CurrentExercice::class)->get(), auth()->user());
    }

    public function rate(int $value, int $base): ?int
    {
        return $base > 0 ? (int) round($value / $base * 100) : null;
    }
}; ?>

<div class="flex flex-col gap-6">
    @php
        $d = $this->data;
        $f = $d['finance']['total'];
        $fmt = fn ($v) => number_format($v, 0, ',', ' ');
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Tableau de bord</flux:heading>
            <flux:text class="mt-1">Pilotage de l'{{ app(CurrentExercice::class)->get()->label }} : exécution financière, validation des TDR, rapports et parc.</flux:text>
        </div>
        <flux:button icon="banknotes" :href="route('finance.index')" wire:navigate>Suivi financier détaillé</flux:button>
    </div>

    {{-- Chiffres clés --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <flux:subheading>Budget planifié</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $fmt($f['planned']) }}</div>
            <flux:text size="sm">FCFA · {{ $d['activities']['total'] }} activités</flux:text>
        </flux:card>
        <flux:card>
            <flux:subheading>Taux d'engagement</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $this->rate($f['engaged'], $f['planned']) ?? '—' }} %</div>
            <flux:text size="sm">{{ $fmt($f['engaged']) }} FCFA engagés</flux:text>
        </flux:card>
        <flux:card>
            <flux:subheading>Taux de décaissement</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $this->rate($f['paid'], $f['planned']) ?? '—' }} %</div>
            <flux:text size="sm">{{ $fmt($f['paid']) }} FCFA payés · reste à payer {{ $fmt($f['engaged'] - $f['paid']) }}</flux:text>
        </flux:card>
        <flux:card>
            <flux:subheading>Délai moyen de validation d'un TDR</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $d['tdr']['avg_days'] !== null ? str_replace('.', ',', $d['tdr']['avg_days']).' j' : '—' }}</div>
            <flux:text size="sm">de la soumission à l'approbation</flux:text>
        </flux:card>
    </div>

    {{-- Points d'attention --}}
    @php
        $alerts = array_filter([
            $d['tdr']['late'] ? ['red', 'clock', $d['tdr']['late'].' TDR en attente depuis plus de '.\App\Support\Dashboard::LATE_DAYS.' jours', route('validations', ['vue' => 'all'])] : null,
            $d['activities']['a_risque'] ? ['orange', 'exclamation-triangle', $d['activities']['a_risque'].' activité(s) dont le démarrage est passé sans TDR approuvé', route('activities.index', ['tdr' => 'none'])] : null,
            $d['reports']['late'] ? ['orange', 'document-text', $d['reports']['late'].' rapport(s) attendu(s) non remis', route('reports.index', ['vue' => 'calendrier'])] : null,
            $d['activities']['sans_direction'] ? ['zinc', 'building-office-2', $d['activities']['sans_direction'].' activité(s) sans direction responsable', route('activities.index')] : null,
        ]);
    @endphp
    @if ($alerts)
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($alerts as [$color, $icon, $text, $url])
                <a href="{{ $url }}" wire:navigate class="block">
                    <flux:callout :color="$color" :icon="$icon" :heading="$text" class="transition hover:opacity-90" />
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- Exécution par niveau --}}
        <flux:card class="space-y-3 xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">Exécution par {{ $volet === 'projet' ? 'composante' : 'domaine de résultats' }}</flux:heading>
                <flux:radio.group wire:model.live="volet" variant="segmented" size="sm">
                    @foreach (Volet::cases() as $v)
                        <flux:radio :value="$v->value" :label="$v->label()" />
                    @endforeach
                </flux:radio.group>
            </div>
            <div class="flex gap-4 text-xs text-zinc-600 dark:text-zinc-300" aria-hidden="true">
                <span class="flex items-center gap-1.5"><span class="h-2 w-4 rounded-full" style="background: var(--series-2)"></span>Engagé</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-4 rounded-full" style="background: var(--series-1)"></span>Payé</span>
                <span class="ms-auto">en % du planifié (FCFA à droite)</span>
            </div>
            @forelse ($d['levels'][$volet] as $level)
                <x-dashboard.bar :label="$level['label']" :code="$level['code']" :planned="$level['planned']" :engaged="$level['engaged']" :paid="$level['paid']" />
            @empty
                <flux:text>Aucune activité pour ce volet.</flux:text>
            @endforelse
        </flux:card>

        {{-- TDR --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg">TDR</flux:heading>
            <ul class="space-y-2 text-sm">
                @foreach ($d['tdr']['by_state'] as ['state' => $state, 'count' => $count])
                    <li class="flex items-center justify-between">
                        <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
                        <span class="font-medium tabular-nums">{{ $count }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($d['tdr']['by_step'])
                <div>
                    <flux:subheading class="mb-1">En attente par étape</flux:subheading>
                    <ul class="space-y-1 text-sm">
                        @foreach ($d['tdr']['by_step'] as $step => $count)
                            <li class="flex justify-between"><span>{{ $step }}</span><span class="tabular-nums">{{ $count }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </flux:card>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <flux:subheading>Activités</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $d['activities']['en_cours'] }} <span class="text-base font-normal text-zinc-500">en cours</span></div>
            <flux:text size="sm">{{ $d['activities']['terminees'] }} terminée(s) sur {{ $d['activities']['total'] }}</flux:text>
        </flux:card>
        <flux:card>
            <flux:subheading>Rapports remis à temps</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $d['reports']['rate'] !== null ? $d['reports']['rate'].' %' : '—' }}</div>
            <flux:text size="sm">{{ $d['reports']['delivered'] }} sur {{ $d['reports']['due'] }} échus · {{ $d['reports']['to_validate'] }} à valider</flux:text>
        </flux:card>
        <flux:card>
            <flux:subheading>Parc automobile</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $d['fleet']['available'] }} <span class="text-base font-normal text-zinc-500">/ {{ $d['fleet']['total'] }} disponibles</span></div>
            <flux:text size="sm">{{ $d['fleet']['out'] }} en sortie · {{ $d['fleet']['alerts'] }} alerte(s)</flux:text>
        </flux:card>
        <flux:card>
            <flux:subheading>TDR en validation</flux:subheading>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $d['tdr']['in_review'] }}</div>
            <flux:text size="sm">dont {{ $d['tdr']['late'] }} depuis plus de {{ \App\Support\Dashboard::LATE_DAYS }} jours</flux:text>
        </flux:card>
    </div>

    {{-- Directions --}}
    <div>
        <flux:heading size="lg" class="mb-2">Par direction</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Direction</flux:table.column>
                <flux:table.column align="end">Activités</flux:table.column>
                <flux:table.column align="end">Planifié</flux:table.column>
                <flux:table.column align="end">Payé</flux:table.column>
                <flux:table.column align="end">Décaissement</flux:table.column>
                <flux:table.column align="end">TDR approuvés</flux:table.column>
                <flux:table.column align="end">Rapports en retard</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($d['directions'] as $row)
                    @php
                        $r = $this->rate($row['paid'], $row['planned']);
                    @endphp
                    <flux:table.row :key="$row['code']">
                        <flux:table.cell class="font-medium">{{ $row['code'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['activities'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['planned']) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $fmt($row['paid']) }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-2" title="{{ $fmt($row['paid']) }} payés sur {{ $fmt($row['planned']) }} FCFA">
                                <div class="h-1.5 w-16 rounded-full" style="background: var(--chart-track)"><div class="h-1.5 rounded-full" style="width: {{ min(100, $r ?? 0) }}%; background: var(--series-1)"></div></div>
                                <span class="w-10 tabular-nums">{{ $r !== null ? $r.' %' : '—' }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $row['tdr_approved'] }} / {{ $row['activities'] }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($row['late_reports'])
                                <flux:badge size="sm" color="red">{{ $row['late_reports'] }}</flux:badge>
                            @else
                                <span class="text-zinc-400">0</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="7" class="py-8 text-center">Aucune activité rattachée à une direction.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
