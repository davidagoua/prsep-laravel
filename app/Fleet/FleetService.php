<?php

namespace App\Fleet;

use App\Enums\Fleet\VehicleStatus;
use App\Models\Fleet\Vehicle;
use App\Models\Fleet\VehicleMaintenance;
use App\Models\Fleet\VehicleTrip;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Règles du parc :
 * - une sortie n'est possible que pour un véhicule disponible, le véhicule passe « en sortie » ;
 * - le kilométrage ne recule jamais (départ ≥ compteur, retour ≥ départ) ;
 * - au retour, le compteur est mis à jour et le véhicule redevient disponible, ou part en entretien s'il est immobilisé ;
 * - un entretien met à jour le compteur, fixe le prochain entretien et peut remettre le véhicule en service.
 */
class FleetService
{
    /** @param  array<string, mixed>  $data */
    public function startTrip(Vehicle $vehicle, User $user, array $data): VehicleTrip
    {
        Gate::forUser($user)->authorize('manage-fleet');

        return DB::transaction(function () use ($vehicle, $user, $data) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);

            if ($vehicle->status !== VehicleStatus::Disponible) {
                throw new FleetException("{$vehicle->plate} n'est pas disponible ({$vehicle->status->label()}).");
            }
            if ($data['km_out'] < $vehicle->mileage) {
                throw new FleetException('Le kilométrage de départ ('.$this->km($data['km_out']).') est inférieur au compteur du véhicule ('.$this->km($vehicle->mileage).').');
            }
            if (! empty($data['expected_return_at']) && Carbon::parse($data['expected_return_at'])->lt(Carbon::parse($data['departed_at']))) {
                throw new FleetException('Le retour prévu doit être postérieur au départ.');
            }

            $trip = $vehicle->trips()->create($data + ['user_id' => $user->id]);
            $vehicle->update(['status' => VehicleStatus::EnSortie, 'mileage' => $data['km_out']]);

            return $trip;
        });
    }

    /**
     * @param  list<array{type: string, note: ?string}>  $incidents
     */
    public function returnTrip(VehicleTrip $trip, User $user, string $returnedAt, int $kmIn, array $incidents = [], bool $immobilised = false, ?string $notes = null): VehicleTrip
    {
        Gate::forUser($user)->authorize('manage-fleet');

        if ($trip->returned_at) {
            throw new FleetException('Ce retour a déjà été enregistré.');
        }
        if ($kmIn < $trip->km_out) {
            throw new FleetException('Le kilométrage de retour ('.$this->km($kmIn).') est inférieur au kilométrage de départ ('.$this->km($trip->km_out).').');
        }
        if (Carbon::parse($returnedAt)->lt($trip->departed_at)) {
            throw new FleetException('La date de retour est antérieure au départ.');
        }

        return DB::transaction(function () use ($trip, $returnedAt, $kmIn, $incidents, $immobilised, $notes) {
            $trip->update(['returned_at' => $returnedAt, 'km_in' => $kmIn, 'return_notes' => $notes]);

            foreach ($incidents as $incident) {
                $trip->incidents()->create([
                    'vehicle_id' => $trip->vehicle_id,
                    'type' => $incident['type'],
                    'note' => $incident['note'] ?? null,
                    'occurred_on' => Carbon::parse($returnedAt)->toDateString(),
                ]);
            }

            $trip->vehicle->update([
                'mileage' => max($trip->vehicle->mileage, $kmIn),
                'status' => $immobilised ? VehicleStatus::EnEntretien : VehicleStatus::Disponible,
            ]);

            return $trip;
        });
    }

    /** @param  array<string, mixed>  $data  types, done_on, mileage, cost, garage, notes, next_service_km, next_service_on, back_in_service */
    public function recordMaintenance(Vehicle $vehicle, User $user, array $data): VehicleMaintenance
    {
        Gate::forUser($user)->authorize('manage-fleet');

        if ($vehicle->status === VehicleStatus::EnSortie) {
            throw new FleetException('Le véhicule est en sortie : enregistrez d\'abord son retour.');
        }
        if (empty($data['types'])) {
            throw new FleetException('Indiquez au moins une opération d\'entretien.');
        }

        return DB::transaction(function () use ($vehicle, $user, $data) {
            $maintenance = $vehicle->maintenances()->create([
                'types' => array_values($data['types']),
                'done_on' => $data['done_on'],
                'mileage' => $data['mileage'],
                'cost' => $data['cost'] ?? 0,
                'garage' => $data['garage'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $user->id,
            ]);

            $vehicle->update(array_filter([
                'mileage' => max($vehicle->mileage, (int) $data['mileage']),
                'next_service_km' => $data['next_service_km'] ?? null,
                'next_service_on' => $data['next_service_on'] ?? null,
                'status' => ($data['back_in_service'] ?? false) && $vehicle->status === VehicleStatus::EnEntretien ? VehicleStatus::Disponible : null,
            ], fn ($v) => $v !== null));

            return $maintenance;
        });
    }

    public function setStatus(Vehicle $vehicle, User $user, VehicleStatus $status): Vehicle
    {
        Gate::forUser($user)->authorize('manage-fleet');

        if ($vehicle->status === VehicleStatus::EnSortie || $status === VehicleStatus::EnSortie) {
            throw new FleetException('Le statut « en sortie » est géré par les sorties et les retours.');
        }

        $vehicle->update(['status' => $status]);

        return $vehicle;
    }

    private function km(int $value): string
    {
        return number_format($value, 0, ',', ' ').' km';
    }
}
