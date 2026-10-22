<?php

use App\Enums\Fleet\IncidentType;
use App\Enums\Fleet\MaintenanceType;
use App\Enums\Fleet\TripType;
use App\Enums\Fleet\VehicleStatus;
use App\Fleet\FleetException;
use App\Fleet\FleetService;
use App\Models\Direction;
use App\Models\Fleet\Vehicle;
use App\Models\Fleet\VehicleTrip;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/*
 * Fenêtres partagées du parc : véhicule, sortie, retour, entretien.
 * Ouvertes par événement depuis n'importe quelle page du parc ; signalent « fleet-updated » après enregistrement.
 */
new class extends Component {
    public ?int $vehicleId = null;
    public ?int $tripId = null;

    // Véhicule
    public string $plate = '';
    public string $brand = '';
    public string $model = '';
    public $year = '';
    public string $chassis = '';
    public $mileage = 0;
    public string $notes = '';

    // Sortie
    public string $t_type = 'mission';
    public string $t_purpose = '';
    public string $t_destination = '';
    public string $t_manager = '';
    public string $t_driver = '';
    public ?string $t_direction = null;
    public string $t_departed_at = '';
    public string $t_expected_at = '';
    public $t_km_out = 0;

    // Retour
    public string $r_returned_at = '';
    public $r_km_in = '';
    /** @var list<string> */
    public array $r_incidents = [];
    public string $r_incident_note = '';
    public bool $r_immobilised = false;
    public string $r_notes = '';

    // Entretien
    /** @var list<string> */
    public array $m_types = [];
    public string $m_done_on = '';
    public $m_mileage = 0;
    public $m_cost = '';
    public string $m_garage = '';
    public string $m_notes = '';
    public $m_next_km = '';
    public string $m_next_on = '';
    public bool $m_back = true;

    #[Computed]
    public function vehicle(): ?Vehicle
    {
        return $this->vehicleId ? Vehicle::find($this->vehicleId) : null;
    }

    #[Computed]
    public function trip(): ?VehicleTrip
    {
        return $this->tripId ? VehicleTrip::with('vehicle')->find($this->tripId) : null;
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code']);
    }

    #[On('fleet-vehicle-form')]
    public function openVehicle(?int $vehicleId = null): void
    {
        $this->authorize('manage-fleet');
        $this->resetValidation();
        $this->vehicleId = $vehicleId;
        $v = $vehicleId ? Vehicle::findOrFail($vehicleId) : null;
        [$this->plate, $this->brand, $this->model, $this->year, $this->chassis, $this->mileage, $this->notes]
            = [$v->plate ?? '', $v->brand ?? '', $v->model ?? '', $v->year ?? '', $v->chassis ?? '', $v->mileage ?? 0, $v->notes ?? ''];
        Flux::modal('fleet-vehicle')->show();
    }

    public function saveVehicle(): void
    {
        $this->authorize('manage-fleet');
        $data = $this->validate([
            'plate' => ['required', 'string', 'max:30', Rule::unique('vehicles', 'plate')->ignore($this->vehicleId)],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1980,'.(now()->year + 1)],
            'chassis' => ['nullable', 'string', 'max:100'],
            'mileage' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ], attributes: ['plate' => 'immatriculation', 'brand' => 'marque', 'model' => 'modèle', 'year' => 'année', 'mileage' => 'kilométrage']);
        $data['plate'] = strtoupper(trim($data['plate']));
        $data['year'] = $data['year'] ?: null;

        if ($this->vehicleId && $data['mileage'] < $this->vehicle->mileage) {
            $this->addError('mileage', 'Le kilométrage ne peut pas diminuer.');

            return;
        }

        $vehicle = Vehicle::updateOrCreate(['id' => $this->vehicleId], $data);
        $this->done('fleet-vehicle', $this->vehicleId ? 'Véhicule mis à jour.' : "Véhicule {$vehicle->plate} ajouté.");
    }

    #[On('fleet-start-trip')]
    public function openTrip(int $vehicleId): void
    {
        $this->authorize('manage-fleet');
        $this->resetValidation();
        $this->vehicleId = $vehicleId;
        unset($this->vehicle);
        $this->reset('t_purpose', 't_destination', 't_manager', 't_driver', 't_direction', 't_expected_at');
        $this->t_type = TripType::Mission->value;
        $this->t_departed_at = now()->format('Y-m-d\TH:i');
        $this->t_km_out = $this->vehicle->mileage;
        Flux::modal('fleet-trip')->show();
    }

    public function startTrip(FleetService $fleet): void
    {
        $data = $this->validate([
            't_type' => ['required', Rule::enum(TripType::class)],
            't_purpose' => ['nullable', 'string'],
            't_destination' => ['nullable', 'string', 'max:255'],
            't_manager' => ['required', 'string', 'max:255'],
            't_driver' => ['required', 'string', 'max:255'],
            't_direction' => ['nullable', 'exists:directions,id'],
            't_departed_at' => ['required', 'date'],
            't_expected_at' => ['nullable', 'date'],
            't_km_out' => ['required', 'integer', 'min:0'],
        ], attributes: ['t_manager' => 'responsable', 't_driver' => 'chauffeur', 't_departed_at' => 'départ', 't_km_out' => 'kilométrage de départ']);

        $this->run(fn () => $fleet->startTrip($this->vehicle, auth()->user(), [
            'type' => $data['t_type'],
            'purpose' => $data['t_purpose'] ?: null,
            'destination' => $data['t_destination'] ?: null,
            'manager_name' => $data['t_manager'],
            'driver_name' => $data['t_driver'],
            'direction_id' => $data['t_direction'] ?: null,
            'departed_at' => $data['t_departed_at'],
            'expected_return_at' => $data['t_expected_at'] ?: null,
            'km_out' => (int) $data['t_km_out'],
        ]), 'fleet-trip', 'Sortie enregistrée.', 't_km_out');
    }

    #[On('fleet-return-trip')]
    public function openReturn(int $tripId): void
    {
        $this->authorize('manage-fleet');
        $this->resetValidation();
        $this->tripId = $tripId;
        unset($this->trip);
        $this->reset('r_km_in', 'r_incidents', 'r_incident_note', 'r_immobilised', 'r_notes');
        $this->r_returned_at = now()->format('Y-m-d\TH:i');
        Flux::modal('fleet-return')->show();
    }

    public function returnTrip(FleetService $fleet): void
    {
        $data = $this->validate([
            'r_returned_at' => ['required', 'date'],
            'r_km_in' => ['required', 'integer', 'min:0'],
            'r_incidents.*' => [Rule::enum(IncidentType::class)],
            'r_incident_note' => ['nullable', 'string'],
        ], attributes: ['r_km_in' => 'kilométrage de retour', 'r_returned_at' => 'date de retour']);

        $incidents = array_map(fn ($type) => ['type' => $type, 'note' => $this->r_incident_note ?: null], $this->r_incidents);

        $this->run(fn () => $fleet->returnTrip($this->trip, auth()->user(), $data['r_returned_at'], (int) $data['r_km_in'], $incidents, $this->r_immobilised, $this->r_notes ?: null),
            'fleet-return', $this->r_immobilised ? 'Retour enregistré : véhicule immobilisé pour entretien.' : 'Retour enregistré.', 'r_km_in');
    }

    #[On('fleet-maintenance')]
    public function openMaintenance(int $vehicleId): void
    {
        $this->authorize('manage-fleet');
        $this->resetValidation();
        $this->vehicleId = $vehicleId;
        unset($this->vehicle);
        $this->reset('m_types', 'm_cost', 'm_garage', 'm_notes', 'm_next_on');
        $this->m_done_on = today()->toDateString();
        $this->m_mileage = $this->vehicle->mileage;
        $this->m_next_km = $this->vehicle->mileage + 5000;
        $this->m_back = true;
        Flux::modal('fleet-maintenance')->show();
    }

    public function saveMaintenance(FleetService $fleet): void
    {
        $data = $this->validate([
            'm_types' => ['required', 'array', 'min:1'],
            'm_types.*' => [Rule::enum(MaintenanceType::class)],
            'm_done_on' => ['required', 'date'],
            'm_mileage' => ['required', 'integer', 'min:0'],
            'm_cost' => ['nullable', 'integer', 'min:0'],
            'm_garage' => ['nullable', 'string', 'max:255'],
            'm_notes' => ['nullable', 'string'],
            'm_next_km' => ['nullable', 'integer', 'gt:m_mileage'],
            'm_next_on' => ['nullable', 'date', 'after:m_done_on'],
        ], ['m_types.required' => 'Cochez au moins une opération.', 'm_next_km.gt' => 'Le prochain entretien doit être au-delà du kilométrage actuel.'],
            ['m_mileage' => 'kilométrage', 'm_done_on' => 'date', 'm_cost' => 'coût', 'm_next_on' => 'date du prochain entretien']);

        $this->run(fn () => $fleet->recordMaintenance($this->vehicle, auth()->user(), [
            'types' => $data['m_types'],
            'done_on' => $data['m_done_on'],
            'mileage' => (int) $data['m_mileage'],
            'cost' => (int) ($data['m_cost'] ?: 0),
            'garage' => $data['m_garage'] ?: null,
            'notes' => $data['m_notes'] ?: null,
            'next_service_km' => $data['m_next_km'] ? (int) $data['m_next_km'] : null,
            'next_service_on' => $data['m_next_on'] ?: null,
            'back_in_service' => $this->m_back,
        ]), 'fleet-maintenance', 'Entretien enregistré.', 'm_types');
    }

    #[On('fleet-set-status')]
    public function setStatus(int $vehicleId, string $status, FleetService $fleet): void
    {
        $this->run(fn () => $fleet->setStatus(Vehicle::findOrFail($vehicleId), auth()->user(), VehicleStatus::from($status)), null, 'Statut mis à jour.');
    }

    private function run(callable $action, ?string $modal, string $success, ?string $errorField = null): void
    {
        try {
            $action();
        } catch (FleetException $e) {
            $errorField ? $this->addError($errorField, $e->getMessage()) : Flux::toast($e->getMessage(), variant: 'danger');

            return;
        } catch (AuthorizationException) {
            Flux::toast('Réservé au gestionnaire du parc.', variant: 'danger');

            return;
        }

        $this->done($modal, $success);
    }

    private function done(?string $modal, string $message): void
    {
        if ($modal) {
            Flux::modal($modal)->close();
        }
        Flux::toast($message, variant: 'success');
        $this->dispatch('fleet-updated');
    }
}; ?>

<div>
    <flux:modal name="fleet-vehicle" class="w-full max-w-lg">
        <form wire:submit="saveVehicle" class="space-y-4">
            <flux:heading size="lg">{{ $vehicleId && $this->vehicle ? 'Modifier '.$this->vehicle->plate : 'Nouveau véhicule' }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="plate" label="Immatriculation" required placeholder="1234 AB 01" />
                <flux:input wire:model="chassis" label="N° de châssis" />
                <flux:input wire:model="brand" label="Marque" required placeholder="Toyota" />
                <flux:input wire:model="model" label="Modèle" required placeholder="Land Cruiser" />
                <flux:input wire:model="year" type="number" label="Année" />
                <flux:input wire:model="mileage" type="number" min="0" label="Kilométrage" required />
            </div>
            <flux:textarea wire:model="notes" label="Notes" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="fleet-trip" class="w-full max-w-lg">
        <form wire:submit="startTrip" class="space-y-4">
            <flux:heading size="lg">Sortie · {{ $this->vehicle?->plate }}</flux:heading>
            <flux:radio.group wire:model="t_type" variant="segmented" label="Type">
                @foreach (TripType::cases() as $type)
                    <flux:radio :value="$type->value" :label="$type->label()" />
                @endforeach
            </flux:radio.group>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="t_manager" label="Responsable" required />
                <flux:input wire:model="t_driver" label="Chauffeur" required />
                <flux:input wire:model="t_destination" label="Destination" />
                <flux:select wire:model="t_direction" label="Direction" placeholder="Facultatif">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->directions as $d)
                        <flux:select.option :value="$d->id">{{ $d->code }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="t_departed_at" type="datetime-local" label="Départ" required />
                <flux:input wire:model="t_expected_at" type="datetime-local" label="Retour prévu" />
            </div>
            <flux:input wire:model="t_km_out" type="number" min="0" label="Kilométrage au départ" required
                :description="'Compteur actuel : '.number_format($this->vehicle?->mileage ?? 0, 0, ',', ' ').' km'" />
            <flux:textarea wire:model="t_purpose" label="Objet de la sortie" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-right-start-on-rectangle">Enregistrer la sortie</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="fleet-return" class="w-full max-w-lg">
        <form wire:submit="returnTrip" class="space-y-4">
            <flux:heading size="lg">Retour · {{ $this->trip?->vehicle->plate }}</flux:heading>
            @if ($this->trip)
                <flux:text size="sm">Parti le {{ $this->trip->departed_at->format('d/m/Y à H:i') }} avec {{ number_format($this->trip->km_out, 0, ',', ' ') }} km · {{ $this->trip->driver_name }}{{ $this->trip->destination ? ' · '.$this->trip->destination : '' }}</flux:text>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="r_returned_at" type="datetime-local" label="Retour" required />
                <flux:input wire:model="r_km_in" type="number" min="0" label="Kilométrage au retour" required />
            </div>
            <flux:checkbox.group wire:model="r_incidents" label="Incidents">
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach (IncidentType::cases() as $type)
                        <flux:checkbox :value="$type->value" :label="$type->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>
            <flux:textarea wire:model="r_incident_note" label="Détail des incidents" rows="2" placeholder="Facultatif" />
            <flux:switch wire:model="r_immobilised" label="Véhicule immobilisé" description="Il passe « en entretien » au lieu de redevenir disponible." />
            <flux:textarea wire:model="r_notes" label="Observations" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-left-end-on-rectangle">Enregistrer le retour</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="fleet-maintenance" class="w-full max-w-2xl">
        <form wire:submit="saveMaintenance" class="space-y-4">
            <flux:heading size="lg">Entretien · {{ $this->vehicle?->plate }}</flux:heading>
            <flux:checkbox.group wire:model="m_types" label="Opérations">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (MaintenanceType::cases() as $type)
                        <flux:checkbox :value="$type->value" :label="$type->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="m_done_on" type="date" label="Date" required />
                <flux:input wire:model="m_mileage" type="number" min="0" label="Kilométrage" required />
                <flux:input wire:model="m_cost" type="number" min="0" label="Coût (FCFA)" />
            </div>
            <flux:input wire:model="m_garage" label="Garage" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="m_next_km" type="number" min="0" label="Prochain entretien à (km)" />
                <flux:input wire:model="m_next_on" type="date" label="ou au plus tard le" />
            </div>
            <flux:textarea wire:model="m_notes" label="Notes" rows="2" />
            @if ($this->vehicle?->status === VehicleStatus::EnEntretien)
                <flux:switch wire:model="m_back" label="Remettre le véhicule en service" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="wrench-screwdriver">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
