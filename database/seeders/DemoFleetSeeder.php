<?php

namespace Database\Seeders;

use App\Enums\Fleet\VehicleStatus;
use App\Fleet\FleetService;
use App\Models\Direction;
use App\Models\Fleet\Vehicle;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Parc de démonstration (environnement local) : véhicules, sorties, un retour en retard,
 * un incident et des entretiens, dont un à prévoir.
 */
class DemoFleetSeeder extends Seeder
{
    public function run(FleetService $fleet): void
    {
        $manager = User::where('email', 'gestionnaire.parc@sigpro.test')->first();
        if (! $manager || Vehicle::exists()) {
            return;
        }

        $vehicles = collect([
            ['plate' => '2154 HK 01', 'brand' => 'Toyota', 'model' => 'Land Cruiser', 'year' => 2021, 'mileage' => 84250, 'next_service_km' => 90000],
            ['plate' => '3378 JA 01', 'brand' => 'Toyota', 'model' => 'Hilux', 'year' => 2022, 'mileage' => 46120, 'next_service_km' => 46500],
            ['plate' => '1092 GT 01', 'brand' => 'Nissan', 'model' => 'Patrol', 'year' => 2019, 'mileage' => 132800, 'next_service_km' => 140000],
            ['plate' => '4410 KB 01', 'brand' => 'Mitsubishi', 'model' => 'L200', 'year' => 2023, 'mileage' => 21560, 'next_service_on' => today()->addDays(9)],
            ['plate' => '0875 FZ 01', 'brand' => 'Hyundai', 'model' => 'H1', 'year' => 2018, 'mileage' => 158900],
        ])->map(fn ($data) => Vehicle::create($data));

        $dspa = Direction::where('code', 'DSPA')->value('id');

        // Mission à Bouaké revenue avec une crevaison.
        $trip = $fleet->startTrip($vehicles[0], $manager, [
            'type' => 'mission', 'purpose' => 'Supervision des ateliers régionaux de formation', 'destination' => 'Bouaké',
            'manager_name' => 'Koffi Yao', 'driver_name' => 'Traoré Moussa', 'direction_id' => $dspa,
            'departed_at' => now()->subDays(12)->setTime(7, 30), 'expected_return_at' => now()->subDays(9)->setTime(18, 0), 'km_out' => 84250,
        ]);
        $fleet->returnTrip($trip, $manager, now()->subDays(9)->setTime(17, 45)->toDateTimeString(), 85010, [['type' => 'crevaison', 'note' => 'Pneu arrière droit remplacé à Yamoussoukro']]);

        // Course en cours, retour attendu hier.
        $fleet->startTrip($vehicles[2], $manager, [
            'type' => 'course', 'purpose' => 'Livraison des guides pédagogiques', 'destination' => 'DRENA Abidjan 3',
            'manager_name' => 'Aka Marie', 'driver_name' => 'Kouamé Serge', 'direction_id' => $dspa,
            'departed_at' => now()->subDays(2)->setTime(8, 0), 'expected_return_at' => now()->subDay()->setTime(17, 0), 'km_out' => 132800,
        ]);

        // Entretiens.
        $fleet->recordMaintenance($vehicles[1], $manager, [
            'types' => ['vidange', 'filtre_huile'], 'done_on' => now()->subMonths(4)->toDateString(), 'mileage' => 41500,
            'cost' => 85000, 'garage' => 'Garage CFAO Abidjan', 'next_service_km' => 46500,
        ]);
        $fleet->setStatus($vehicles[4], $manager, VehicleStatus::EnEntretien);
    }
}
