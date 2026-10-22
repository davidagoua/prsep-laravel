<?php

use App\Enums\Fleet\TripType;
use App\Models\Fleet\VehicleTrip;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Sorties de véhicules')] class extends Component {
    use WithPagination;

    /** en_cours · terminees · toutes */
    #[Url]
    public string $etat = 'en_cours';

    #[Url]
    public string $mois = '';

    #[Url]
    public string $type = '';

    public function updated($property): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function trips()
    {
        return VehicleTrip::query()
            ->with(['vehicle', 'direction', 'incidents'])
            ->when($this->etat === 'en_cours', fn ($q) => $q->ongoing())
            ->when($this->etat === 'terminees', fn ($q) => $q->whereNotNull('returned_at'))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->mois, fn ($q) => $q->whereBetween('departed_at', [
                \Illuminate\Support\Carbon::parse($this->mois.'-01')->startOfMonth(),
                \Illuminate\Support\Carbon::parse($this->mois.'-01')->endOfMonth(),
            ]))
            ->latest('departed_at')
            ->paginate(25);
    }

    #[On('fleet-updated')]
    public function refreshData(): void
    {
        unset($this->trips);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('fleet.index')" wire:navigate>Parc automobile</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>Sorties</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1">Sorties de véhicules</flux:heading>
        <flux:text class="mt-1">Missions, courses et déplacements, avec kilométrage et incidents.</flux:text>
    </div>

    <div class="grid gap-3 sm:flex sm:flex-wrap sm:items-center">
        <flux:radio.group wire:model.live="etat" variant="segmented" size="sm">
            <flux:radio value="en_cours" label="En cours" />
            <flux:radio value="terminees" label="Terminées" />
            <flux:radio value="toutes" label="Toutes" />
        </flux:radio.group>
        <flux:select wire:model.live="type" class="sm:max-w-44">
            <flux:select.option value="">Tous les types</flux:select.option>
            @foreach (TripType::cases() as $t)
                <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="mois" type="month" class="sm:max-w-44" aria-label="Mois" />
    </div>

    <flux:table :paginate="$this->trips">
        <flux:table.columns>
            <flux:table.column>Véhicule</flux:table.column>
            <flux:table.column>Sortie</flux:table.column>
            <flux:table.column>Départ</flux:table.column>
            <flux:table.column>Retour</flux:table.column>
            <flux:table.column align="end">Distance</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->trips as $trip)
                <flux:table.row :key="$trip->id">
                    <flux:table.cell>
                        <a href="{{ route('fleet.vehicle', $trip->vehicle) }}" wire:navigate class="font-mono font-semibold hover:underline">{{ $trip->vehicle->plate }}</a>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-md whitespace-normal!">
                        <div class="font-medium">{{ $trip->type->label() }}{{ $trip->destination ? ' · '.$trip->destination : '' }}</div>
                        <div class="text-xs text-zinc-500">{{ $trip->driver_name }} · resp. {{ $trip->manager_name }}{{ $trip->direction ? ' · '.$trip->direction->code : '' }}</div>
                        @foreach ($trip->incidents as $incident)
                            <flux:badge size="sm" color="red" class="mt-1">{{ $incident->type->label() }}</flux:badge>
                        @endforeach
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $trip->departed_at->format('d/m/Y H:i') }}<div class="text-xs text-zinc-500">{{ number_format($trip->km_out, 0, ',', ' ') }} km</div></flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        @if ($trip->returned_at)
                            {{ $trip->returned_at->format('d/m/Y H:i') }}<div class="text-xs text-zinc-500">{{ number_format($trip->km_in, 0, ',', ' ') }} km</div>
                        @else
                            <flux:badge size="sm" :color="$trip->isOverdue() ? 'red' : 'blue'">{{ $trip->isOverdue() ? 'En retard' : 'En cours' }}</flux:badge>
                            @if ($trip->expected_return_at)<div class="text-xs text-zinc-500">prévu {{ $trip->expected_return_at->format('d/m H:i') }}</div>@endif
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $trip->distance() !== null ? number_format($trip->distance(), 0, ',', ' ').' km' : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @can('manage-fleet')
                            @unless ($trip->returned_at)
                                <flux:button size="sm" variant="primary" wire:click="$dispatch('fleet-return-trip', { tripId: {{ $trip->id }} })">Retour</flux:button>
                            @endunless
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="6" class="py-10 text-center">Aucune sortie pour ces filtres.</flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @can('manage-fleet')
        <livewire:fleet.actions />
    @endcan
</div>
