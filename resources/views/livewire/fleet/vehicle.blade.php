<?php

use App\Enums\Fleet\VehicleStatus;
use App\Models\Fleet\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public Vehicle $vehicle;

    public string $onglet = 'sorties';

    public function rendering($view): void
    {
        $view->title($this->vehicle->plate);
    }

    #[Computed]
    public function trips()
    {
        return $this->vehicle->trips()->with(['direction', 'incidents'])->limit(50)->get();
    }

    #[Computed]
    public function maintenances()
    {
        return $this->vehicle->maintenances()->with('user')->get();
    }

    #[Computed]
    public function incidents()
    {
        return $this->vehicle->incidents()->with('trip')->get();
    }

    #[Computed]
    public function stats(): array
    {
        $year = $this->vehicle->trips()->whereYear('departed_at', now()->year)->whereNotNull('km_in')->get();

        return [
            'trips' => $year->count(),
            'km' => $year->sum(fn ($t) => $t->distance()),
            'cost' => (int) $this->vehicle->maintenances()->whereYear('done_on', now()->year)->sum('cost'),
        ];
    }

    #[On('fleet-updated')]
    public function refreshData(): void
    {
        $this->vehicle->refresh();
        unset($this->trips, $this->maintenances, $this->incidents, $this->stats);
    }
}; ?>

<div class="flex flex-col gap-6">
    @php($v = $vehicle->loadMissing('currentTrip'))
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('fleet.index')" wire:navigate>Parc automobile</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $v->plate }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl" level="1" class="font-mono">{{ $v->plate }}</flux:heading>
                    <flux:badge :color="$v->status->color()">{{ $v->status->label() }}</flux:badge>
                </div>
                <flux:text class="mt-1">{{ $v->name() }}{{ $v->chassis ? ' · châssis '.$v->chassis : '' }}</flux:text>
            </div>
            @can('manage-fleet')
                <div class="flex flex-wrap gap-2">
                    @if ($v->status === VehicleStatus::Disponible)
                        <flux:button variant="primary" icon="arrow-right-start-on-rectangle" wire:click="$dispatch('fleet-start-trip', { vehicleId: {{ $v->id }} })">Sortie</flux:button>
                    @elseif ($v->currentTrip)
                        <flux:button variant="primary" icon="arrow-left-end-on-rectangle" wire:click="$dispatch('fleet-return-trip', { tripId: {{ $v->currentTrip->id }} })">Retour</flux:button>
                    @endif
                    <flux:button icon="wrench-screwdriver" wire:click="$dispatch('fleet-maintenance', { vehicleId: {{ $v->id }} })" :disabled="$v->status === VehicleStatus::EnSortie">Entretien</flux:button>
                    <flux:dropdown align="end">
                        <flux:button icon="ellipsis-horizontal" aria-label="Autres actions" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="$dispatch('fleet-vehicle-form', { vehicleId: {{ $v->id }} })">Modifier</flux:menu.item>
                            @if ($v->status === VehicleStatus::Disponible)
                                <flux:menu.item icon="wrench" wire:click="$dispatch('fleet-set-status', { vehicleId: {{ $v->id }}, status: 'en_entretien' })">Mettre en entretien</flux:menu.item>
                                <flux:menu.item icon="no-symbol" variant="danger" wire:click="$dispatch('fleet-set-status', { vehicleId: {{ $v->id }}, status: 'hors_service' })" wire:confirm="Mettre {{ $v->plate }} hors service ?">Mettre hors service</flux:menu.item>
                            @elseif (in_array($v->status, [VehicleStatus::EnEntretien, VehicleStatus::HorsService], true))
                                <flux:menu.item icon="check-circle" wire:click="$dispatch('fleet-set-status', { vehicleId: {{ $v->id }}, status: 'disponible' })">Remettre en service</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @endcan
        </div>
    </div>

    @if ($alert = $v->serviceAlert())
        <flux:callout color="orange" icon="wrench-screwdriver" :heading="$alert" />
    @endif
    @if ($v->currentTrip)
        <flux:callout :color="$v->currentTrip->isOverdue() ? 'red' : 'blue'" icon="map-pin">
            <flux:callout.heading>En sortie depuis le {{ $v->currentTrip->departed_at->format('d/m/Y à H:i') }}{{ $v->currentTrip->isOverdue() ? ' — retour en retard' : '' }}</flux:callout.heading>
            <flux:callout.text>
                {{ $v->currentTrip->type->label() }} · {{ $v->currentTrip->driver_name }} (responsable : {{ $v->currentTrip->manager_name }})
                {{ $v->currentTrip->destination ? ' · '.$v->currentTrip->destination : '' }}
                {{ $v->currentTrip->expected_return_at ? ' · retour prévu le '.$v->currentTrip->expected_return_at->format('d/m/Y à H:i') : '' }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['Kilométrage', number_format($v->mileage, 0, ',', ' ').' km'], ['Sorties '.now()->year, $this->stats['trips']], ['Distance '.now()->year, number_format($this->stats['km'], 0, ',', ' ').' km'], ['Entretiens '.now()->year, number_format($this->stats['cost'], 0, ',', ' ').' FCFA']] as [$label, $value])
            <flux:card>
                <flux:subheading>{{ $label }}</flux:subheading>
                <div class="mt-1 text-xl font-semibold tabular-nums">{{ $value }}</div>
            </flux:card>
        @endforeach
    </div>

    <flux:radio.group wire:model.live="onglet" variant="segmented" size="sm" class="self-start">
        <flux:radio value="sorties" :label="'Sorties ('.$this->trips->count().')'" />
        <flux:radio value="entretiens" :label="'Entretiens ('.$this->maintenances->count().')'" />
        <flux:radio value="incidents" :label="'Incidents ('.$this->incidents->count().')'" />
    </flux:radio.group>

    @if ($onglet === 'sorties')
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Départ</flux:table.column>
                <flux:table.column>Sortie</flux:table.column>
                <flux:table.column>Retour</flux:table.column>
                <flux:table.column align="end">Distance</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->trips as $trip)
                    <flux:table.row :key="$trip->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $trip->departed_at->format('d/m/Y H:i') }}</flux:table.cell>
                        <flux:table.cell class="max-w-md whitespace-normal!">
                            <div class="font-medium">{{ $trip->type->label() }}{{ $trip->destination ? ' · '.$trip->destination : '' }}</div>
                            <div class="text-xs text-zinc-500">{{ $trip->driver_name }} · resp. {{ $trip->manager_name }}{{ $trip->direction ? ' · '.$trip->direction->code : '' }}</div>
                            @foreach ($trip->incidents as $incident)
                                <flux:badge size="sm" color="red" class="mt-1">{{ $incident->type->label() }}</flux:badge>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($trip->returned_at)
                                {{ $trip->returned_at->format('d/m/Y H:i') }}
                            @else
                                <flux:badge size="sm" :color="$trip->isOverdue() ? 'red' : 'blue'">{{ $trip->isOverdue() ? 'En retard' : 'En cours' }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $trip->distance() !== null ? number_format($trip->distance(), 0, ',', ' ').' km' : '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="py-8 text-center">Aucune sortie.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @elseif ($onglet === 'entretiens')
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Date</flux:table.column>
                <flux:table.column>Opérations</flux:table.column>
                <flux:table.column align="end">Kilométrage</flux:table.column>
                <flux:table.column align="end">Coût</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->maintenances as $m)
                    <flux:table.row :key="$m->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $m->done_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell class="max-w-lg whitespace-normal!">
                            {{ implode(', ', $m->typeLabels()) }}
                            <div class="text-xs text-zinc-500">{{ collect([$m->garage, $m->notes])->filter()->implode(' · ') }}</div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ number_format($m->mileage, 0, ',', ' ') }} km</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $m->cost ? number_format($m->cost, 0, ',', ' ') : '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4" class="py-8 text-center">Aucun entretien enregistré.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Date</flux:table.column>
                <flux:table.column>Incident</flux:table.column>
                <flux:table.column>Sortie</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->incidents as $incident)
                    <flux:table.row :key="$incident->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $incident->occurred_on->format('d/m/Y') }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="font-medium">{{ $incident->type->label() }}</span>
                            @if ($incident->note) <div class="text-xs text-zinc-500">{{ $incident->note }}</div> @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $incident->trip ? $incident->trip->driver_name.($incident->trip->destination ? ' · '.$incident->trip->destination : '') : '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="3" class="py-8 text-center">Aucun incident.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    @endif

    @can('manage-fleet')
        <livewire:fleet.actions />
    @endcan
</div>
