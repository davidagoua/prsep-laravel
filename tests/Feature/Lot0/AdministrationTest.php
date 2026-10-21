<?php

use App\Enums\ExerciceStatus;
use App\Enums\Role;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $this->actingAs($this->admin);
});

describe('utilisateurs', function () {
    test('créer un utilisateur envoie une invitation', function () {
        Notification::fake();
        $direction = Direction::factory()->create();

        Volt::test('admin.users')
            ->call('create')
            ->set('name', 'Yao Kouassi')
            ->set('email', 'Yao.Kouassi@education.gouv.ci')
            ->set('roles', [Role::PointFocal->value])
            ->set('direction_id', (string) $direction->id)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'yao.kouassi@education.gouv.ci')->firstOrFail();
        expect($user->hasRole(Role::PointFocal->value))->toBeTrue()
            ->and($user->direction_id)->toBe($direction->id);
        Notification::assertSentTo($user, ResetPassword::class);
    });

    test('la direction est obligatoire pour un point focal', function () {
        Volt::test('admin.users')
            ->set('name', 'Sans direction')
            ->set('email', 'sans.direction@example.com')
            ->set('roles', [Role::PointFocal->value])
            ->call('save')
            ->assertHasErrors('direction_id');
    });

    test('la coordination peut ne pas avoir de direction', function () {
        Notification::fake();

        Volt::test('admin.users')
            ->set('name', 'Coordonnateur')
            ->set('email', 'coord@example.com')
            ->set('roles', [Role::Coordination->value])
            ->call('save')
            ->assertHasNoErrors();
    });

    test("l'administrateur ne peut pas se désactiver ni perdre son rôle", function () {
        Volt::test('admin.users')->call('toggleActive', $this->admin->id);
        expect($this->admin->fresh()->is_active)->toBeTrue();

        Volt::test('admin.users')
            ->call('edit', $this->admin->id)
            ->set('roles', [Role::Lecteur->value])
            ->set('is_active', false)
            ->call('save');

        expect($this->admin->fresh())
            ->is_active->toBeTrue()
            ->hasRole(Role::Admin->value)->toBeTrue();
    });

    test('désactiver un autre compte', function () {
        $user = User::factory()->create();

        Volt::test('admin.users')->call('toggleActive', $user->id);

        expect($user->fresh()->is_active)->toBeFalse();
    });

    test('filtrer par rôle', function () {
        tap(User::factory()->create(['name' => 'Agent DSPA']))->assignRole(Role::PointFocal->value);
        tap(User::factory()->create(['name' => 'Chef DAF']))->assignRole(Role::DirecteurCentral->value);

        Volt::test('admin.users')
            ->set('role', Role::PointFocal->value)
            ->assertSee('Agent DSPA')
            ->assertDontSee('Chef DAF');
    });
});

describe('directions', function () {
    test('créer une direction met le sigle en majuscules', function () {
        Volt::test('admin.directions')
            ->call('create')
            ->set('code', 'dtsi')
            ->set('name', 'Direction des Technologies et des Systèmes d\'Information')
            ->call('save')
            ->assertHasNoErrors();

        expect(Direction::where('code', 'DTSI')->exists())->toBeTrue();
    });

    test('le sigle est unique', function () {
        Direction::factory()->create(['code' => 'DAF']);

        Volt::test('admin.directions')
            ->set('code', 'DAF')
            ->set('name', 'Doublon')
            ->call('save')
            ->assertHasErrors('code');
    });

    test('une direction ne peut pas être rattachée à elle-même', function () {
        $direction = Direction::factory()->create();

        Volt::test('admin.directions')
            ->call('edit', $direction->id)
            ->set('parent_id', (string) $direction->id)
            ->call('save')
            ->assertHasErrors('parent_id');
    });
});

describe('exercices', function () {
    test('créer un exercice génère ses périodes', function () {
        Volt::test('admin.exercices')
            ->call('create')
            ->set('year', 2027)
            ->assertSet('label', 'Exercice 2027')
            ->assertSet('starts_on', '2027-01-01')
            ->call('save')
            ->assertHasNoErrors();

        $exercice = Exercice::where('year', 2027)->firstOrFail();
        expect($exercice->status)->toBe(ExerciceStatus::EnPreparation)
            ->and($exercice->periodes()->where('type', 'mois')->count())->toBe(12)
            ->and($exercice->periodes()->where('type', 'trimestre')->count())->toBe(4)
            ->and($exercice->periodes()->where('type', 'semestre')->count())->toBe(2)
            ->and($exercice->periodes()->where('type', 'annee')->count())->toBe(1);

        expect($exercice->periodes()->where('type', 'trimestre')->where('number', 2)->first())
            ->label->toBe('T2 2027')
            ->starts_on->toDateString()->toBe('2027-04-01')
            ->ends_on->toDateString()->toBe('2027-06-30');
    });

    test('un seul exercice est en cours', function () {
        $a = Exercice::factory()->current()->create();
        $b = Exercice::factory()->create();

        Volt::test('admin.exercices')->call('makeCurrent', $b->id);

        expect($a->fresh()->is_current)->toBeFalse()
            ->and($b->fresh()->is_current)->toBeTrue()
            ->and(Exercice::where('is_current', true)->count())->toBe(1);
    });

    test("on ne peut pas clore l'exercice en cours", function () {
        $exercice = Exercice::factory()->current()->create();

        Volt::test('admin.exercices')->call('setStatus', $exercice->id, 'clos');

        expect($exercice->fresh()->status)->toBe(ExerciceStatus::Ouvert);
    });

    test('modifier les dates régénère les périodes', function () {
        $exercice = Exercice::factory()->create(['year' => 2028, 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31']);
        $exercice->generatePeriodes();

        Volt::test('admin.exercices')
            ->call('edit', $exercice->id)
            ->set('starts_on', '2028-07-01')
            ->set('ends_on', '2029-06-30')
            ->call('save')
            ->assertHasNoErrors();

        expect($exercice->periodes()->where('type', 'mois')->orderBy('number')->first()->label)->toBe('Juillet 2028');
    });
});

test('le seeder initialise rôles, directions, exercice et administrateur', function () {
    config(['sigpro.admin.email' => 'admin@sigpro.test']);

    $this->seed(DatabaseSeeder::class);

    expect(Direction::count())->toBe(25)
        ->and(Exercice::current())->not->toBeNull()
        ->and(Exercice::current()->periodes()->count())->toBe(19)
        ->and(User::where('email', 'admin@sigpro.test')->first()->hasRole('admin'))->toBeTrue();
});
