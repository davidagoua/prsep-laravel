<?php

use App\Enums\Fleet\VehicleStatus;
use App\Enums\Role;
use App\Fleet\FleetException;
use App\Fleet\FleetService;
use App\Models\Fleet\Vehicle;
use App\Models\Fleet\VehicleTrip;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->fleet = app(FleetService::class);
    $this->manager = tap(User::factory()->create())->assignRole(Role::GestionnaireParc->value);
    $this->vehicle = Vehicle::create(['plate' => '2154 HK 01', 'brand' => 'Toyota', 'model' => 'Hilux', 'year' => 2022, 'mileage' => 10_000]);
});

function trip(array $overrides = []): array
{
    return $overrides + [
        'type' => 'mission', 'destination' => 'Bouaké', 'manager_name' => 'Koffi Yao', 'driver_name' => 'Traoré Moussa',
        'departed_at' => now()->subHours(5)->toDateTimeString(), 'expected_return_at' => now()->addHours(5)->toDateTimeString(), 'km_out' => 10_000,
    ];
}

describe('règles', function () {
    test('sortie puis retour : statut et compteur suivent', function () {
        $t = $this->fleet->startTrip($this->vehicle, $this->manager, trip());
        expect($this->vehicle->fresh()->status)->toBe(VehicleStatus::EnSortie);

        $this->fleet->returnTrip($t, $this->manager, now()->toDateTimeString(), 10_420, [['type' => 'crevaison', 'note' => 'Pneu changé']]);

        expect($this->vehicle->fresh())->status->toBe(VehicleStatus::Disponible)->mileage->toBe(10_420)
            ->and($t->fresh()->distance())->toBe(420)
            ->and($this->vehicle->incidents()->first()->type->value)->toBe('crevaison');
    });

    test('un véhicule en sortie ne peut pas ressortir', function () {
        $this->fleet->startTrip($this->vehicle, $this->manager, trip());
        $this->fleet->startTrip($this->vehicle->fresh(), $this->manager, trip());
    })->throws(FleetException::class, 'pas disponible');

    test('le kilométrage ne recule jamais', function () {
        expect(fn () => $this->fleet->startTrip($this->vehicle, $this->manager, trip(['km_out' => 9_000])))->toThrow(FleetException::class, 'inférieur au compteur');

        $t = $this->fleet->startTrip($this->vehicle, $this->manager, trip());
        expect(fn () => $this->fleet->returnTrip($t, $this->manager, now()->toDateTimeString(), 9_999))->toThrow(FleetException::class, 'inférieur au kilométrage de départ');
    });

    test('un véhicule immobilisé au retour passe en entretien, l\'entretien le remet en service', function () {
        $t = $this->fleet->startTrip($this->vehicle, $this->manager, trip());
        $this->fleet->returnTrip($t, $this->manager, now()->toDateTimeString(), 10_300, [['type' => 'panne', 'note' => null]], immobilised: true);
        expect($this->vehicle->fresh()->status)->toBe(VehicleStatus::EnEntretien);

        $this->fleet->recordMaintenance($this->vehicle->fresh(), $this->manager, [
            'types' => ['reparation', 'freins'], 'done_on' => today()->toDateString(), 'mileage' => 10_310,
            'cost' => 150_000, 'next_service_km' => 15_000, 'back_in_service' => true,
        ]);

        expect($this->vehicle->fresh())->status->toBe(VehicleStatus::Disponible)->mileage->toBe(10_310)->next_service_km->toBe(15_000);
    });

    test('alertes : entretien dû et retour en retard', function () {
        $this->vehicle->update(['next_service_km' => 10_300]);
        Vehicle::create(['plate' => 'B', 'brand' => 'X', 'model' => 'Y', 'mileage' => 5_000, 'next_service_km' => 20_000]);
        Vehicle::create(['plate' => 'C', 'brand' => 'X', 'model' => 'Y', 'mileage' => 5_000, 'next_service_on' => today()->addDays(3)]);

        expect(Vehicle::serviceDue()->pluck('plate')->all())->toEqualCanonicalizing(['2154 HK 01', 'C'])
            ->and($this->vehicle->fresh()->serviceAlert())->toBe('Entretien dans 300 km');

        $this->fleet->startTrip($this->vehicle, $this->manager, trip(['expected_return_at' => now()->subHour()->toDateTimeString()]));
        expect(VehicleTrip::overdue()->count())->toBe(1);
    });

    test('seul le gestionnaire du parc saisit', function () {
        $coordination = tap(User::factory()->create())->assignRole(Role::Coordination->value);

        $this->fleet->startTrip($this->vehicle, $coordination, trip());
    })->throws(AuthorizationException::class);
});

describe('écrans', function () {
    test('le parc liste les véhicules, leurs statuts et les alertes', function () {
        $this->vehicle->update(['next_service_km' => 10_100]);
        $this->actingAs($this->manager)->get(route('fleet.index'))
            ->assertOk()
            ->assertSee('2154 HK 01')
            ->assertSee('Entretiens à prévoir')
            ->assertSee('Entretien dans 100 km');
    });

    test('sortie et retour depuis les fenêtres partagées', function () {
        $this->actingAs($this->manager);

        Volt::test('fleet.actions')
            ->call('openTrip', $this->vehicle->id)
            ->assertSet('t_km_out', 10_000)
            ->set('t_manager', 'Koffi Yao')
            ->set('t_driver', 'Traoré Moussa')
            ->set('t_destination', 'Korhogo')
            ->call('startTrip')
            ->assertHasNoErrors()
            ->assertDispatched('fleet-updated');

        $trip = VehicleTrip::first();
        Volt::test('fleet.actions')
            ->call('openReturn', $trip->id)
            ->set('r_km_in', 9_000)
            ->call('returnTrip')
            ->assertHasErrors('r_km_in')
            ->set('r_km_in', 10_750)
            ->set('r_incidents', ['panne_seche'])
            ->call('returnTrip')
            ->assertHasNoErrors();

        expect($this->vehicle->fresh())->status->toBe(VehicleStatus::Disponible)->mileage->toBe(10_750);
    });

    test('la fiche véhicule montre l\'historique', function () {
        $t = $this->fleet->startTrip($this->vehicle, $this->manager, trip());
        $this->fleet->returnTrip($t, $this->manager, now()->toDateTimeString(), 10_200);

        $this->actingAs($this->manager)->get(route('fleet.vehicle', $this->vehicle))
            ->assertOk()
            ->assertSee('Bouaké')
            ->assertSee('200 km');
    });

    test('accès : consultation pour la coordination, rien pour le point focal', function () {
        $coordination = tap(User::factory()->create())->assignRole(Role::Coordination->value);
        $focal = tap(User::factory()->create())->assignRole(Role::PointFocal->value);

        $this->actingAs($coordination)->get(route('fleet.index'))->assertOk()->assertDontSee('Nouveau véhicule');
        $this->actingAs($focal)->get(route('fleet.index'))->assertForbidden();
    });

    test('« À traiter » du gestionnaire signale retours en retard et entretiens', function () {
        $this->vehicle->update(['next_service_on' => today()->addDays(2)]);
        Vehicle::create(['plate' => 'RET-1', 'brand' => 'Nissan', 'model' => 'Patrol', 'mileage' => 1000]);
        $this->fleet->startTrip(Vehicle::where('plate', 'RET-1')->first(), $this->manager, trip(['km_out' => 1000, 'expected_return_at' => now()->subHour()->toDateTimeString()]));

        $this->actingAs($this->manager)->get(route('inbox'))
            ->assertSee('Retours de véhicules en retard')
            ->assertSee('RET-1')
            ->assertSee('Entretiens à prévoir');
    });
});
