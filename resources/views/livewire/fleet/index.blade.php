<?php

use App\Enums\Fleet\VehicleStatus;
use App\Models\Fleet\Vehicle;
use App\Models\Fleet\VehicleTrip;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Parc automobile')] class extends Component {
    #[Url]
    public string $statut = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Computed]
    public function vehicles()
    {
        return Vehicle::query()
            ->with('currentTrip')
            ->when($this->statut, fn ($q) => $q->where('status', $this->statut))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('plate', "%{$this->search}%")->orWhereLike('brand', "%{$this->search}%")->orWhereLike('model', "%{$this->search}%")))
            ->orderBy('plate')
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        $byStatus = Vehicle::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(VehicleStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)])->all();
    }

    #[Computed]
    public function overdueTrips()
    {
        return VehicleTrip::overdue()->with('vehicle')->orderBy('expected_return_at')->get();
    }

    #[Computed]
    public function serviceDue()
    {
        return Vehicle::serviceDue()->orderBy('plate')->get();
    }

    #[On('fleet-updated')]
    public function refreshData(): void
    {
        unset($this->vehicles, $this->counts, $this->overdueTrips, $this->serviceDue);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Parc automobile</flux:heading>
            <flux:text class="mt-1">Véhicules, sorties et entretiens. Le statut d'un véhicule suit automatiquement ses sorties et retours.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button icon="list-bullet" :href="route('fleet.trips')" wire:navigate>Sorties</flux:button>
            @can('manage-fleet')
                <flux:button variant="primary" icon="plus" wire:click="$dispatch('fleet-vehicle-form')">Nouveau véhicule</flux:button>
            @endcan
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([[VehicleStatus::Disponible, 'check-circle'], [VehicleStatus::EnSortie, 'arrow-right-start-on-rectangle'], [VehicleStatus::EnEntretien, 'wrench-screwdriver'], [VehicleStatus::HorsService, 'no-symbol']] as [$status, $icon])
            <button type="button" wire:click="$set('statut', '{{ $statut === $status->value ? '' : $status->value }}')" @class([
                'flex items-center gap-3 rounded-xl border bg-white p-4 text-start transition dark:bg-zinc-800',
                'border-prseb-navy-600 ring-1 ring-prseb-navy-600' => $statut === $status->value,
                'border-zinc-200 hover:border-prseb-navy-300 dark:border-zinc-700' => $statut !== $status->value,
            ])>
                <flux:badge :color="$status->color()" class="p-2!"><flux:icon :icon="$icon" class="size-5" /></flux:badge>
                <div>
                    <div class="text-2xl font-semibold tabular-nums">{{ $this->counts[$status->value] }}</div>
                    <div class="text-sm text-zinc-500">{{ $status->label() }}</div>
                </div>
            </button>
        @endforeach
    </div>

    @if ($this->overdueTrips->isNotEmpty() || $this->serviceDue->isNotEmpty())
        <div class="grid gap-4 lg:grid-cols-2">
            @if ($this->overdueTrips->isNotEmpty())
                <flux:callout color="red" icon="clock">
                    <flux:callout.heading>Retours en retard ({{ $this->overdueTrips->count() }})</flux:callout.heading>
                    <flux:callout.text>
                        @foreach ($this->overdueTrips as $trip)
                            <div><a href="{{ route('fleet.vehicle', $trip->vehicle) }}" wire:navigate class="font-medium underline">{{ $trip->vehicle->plate }}</a> — {{ $trip->driver_name }}, attendu {{ $trip->expected_return_at->diffForHumans() }}</div>
                        @endforeach
                    </flux:callout.text>
                </flux:callout>
            @endif
            @if ($this->serviceDue->isNotEmpty())
                <flux:callout color="orange" icon="wrench-screwdriver">
                    <flux:callout.heading>Entretiens à prévoir ({{ $this->serviceDue->count() }})</flux:callout.heading>
                    <flux:callout.text>
                        @foreach ($this->serviceDue as $vehicle)
                            <div><a href="{{ route('fleet.vehicle', $vehicle) }}" wire:navigate class="font-medium underline">{{ $vehicle->plate }}</a> — {{ $vehicle->serviceAlert() }}</div>
                        @endforeach
                    </flux:callout.text>
                </flux:callout>
            @endif
        </div>
    @endif

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher une immatriculation, une marque" class="sm:max-w-sm" clearable />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Véhicule</flux:table.column>
            <flux:table.column>Statut</flux:table.column>
            <flux:table.column align="end">Kilométrage</flux:table.column>
            <flux:table.column>Prochain entretien</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->vehicles as $vehicle)
                <flux:table.row :key="$vehicle->id">
                    <flux:table.cell>
                        <a href="{{ route('fleet.vehicle', $vehicle) }}" wire:navigate class="group">
                            <div class="font-mono font-semibold group-hover:text-prseb-navy-600 group-hover:underline dark:group-hover:text-prseb-navy-300">{{ $vehicle->plate }}</div>
                            <div class="text-xs text-zinc-500">{{ $vehicle->name() }}</div>
                        </a>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$vehicle->status->color()">{{ $vehicle->status->label() }}</flux:badge>
                        @if ($trip = $vehicle->currentTrip)
                            <div class="mt-1 text-xs {{ $trip->isOverdue() ? 'text-red-600' : 'text-zinc-500' }}">
                                {{ $trip->driver_name }}{{ $trip->destination ? ' · '.$trip->destination : '' }}{{ $trip->isOverdue() ? ' · retour en retard' : '' }}
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ number_format($vehicle->mileage, 0, ',', ' ') }} km</flux:table.cell>
                    <flux:table.cell class="text-sm">
                        @if ($alert = $vehicle->serviceAlert())
                            <span class="text-prseb-orange-600">{{ $alert }}</span>
                        @elseif ($vehicle->next_service_km || $vehicle->next_service_on)
                            {{ collect([$vehicle->next_service_km ? number_format($vehicle->next_service_km, 0, ',', ' ').' km' : null, $vehicle->next_service_on?->format('d/m/Y')])->filter()->implode(' ou ') }}
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @can('manage-fleet')
                            @if ($vehicle->status === VehicleStatus::Disponible)
                                <flux:button size="sm" icon="arrow-right-start-on-rectangle" wire:click="$dispatch('fleet-start-trip', { vehicleId: {{ $vehicle->id }} })">Sortie</flux:button>
                            @elseif ($vehicle->currentTrip)
                                <flux:button size="sm" variant="primary" icon="arrow-left-end-on-rectangle" wire:click="$dispatch('fleet-return-trip', { tripId: {{ $vehicle->currentTrip->id }} })">Retour</flux:button>
                            @elseif ($vehicle->status === VehicleStatus::EnEntretien)
                                <flux:button size="sm" icon="wrench-screwdriver" wire:click="$dispatch('fleet-maintenance', { vehicleId: {{ $vehicle->id }} })">Entretien</flux:button>
                            @endif
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-10 text-center">Aucun véhicule{{ $statut || $search ? ' pour ces filtres' : '' }}.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @can('manage-fleet')
        <livewire:fleet.actions />
    @endcan
</div>
