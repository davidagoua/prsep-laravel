<?php

use App\Enums\ExerciceStatus;
use App\Enums\Role;
use App\Imports\Ptba\PtbaAnalyzer;
use App\Imports\Ptba\PtbaImporter;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Volt\Volt;
use Tests\Support\PtbaFixtures;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    $this->daf = Direction::factory()->create(['code' => 'DAF']);
    $this->exercice = Exercice::factory()->current()->create(['year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);

    $analyzer = app(PtbaAnalyzer::class);
    app(PtbaImporter::class)->import($analyzer->analyze(PtbaFixtures::legacyProjet(), $this->exercice), $this->exercice);

    $this->focal = tap(User::factory()->create(['direction_id' => $this->dspa->id]))->assignRole(Role::PointFocal->value);
    $this->coordination = tap(User::factory()->create())->assignRole(Role::Coordination->value);
});

test("l'arborescence cumule les montants à chaque niveau", function () {
    $this->actingAs($this->coordination)
        ->get(route('activities.tree', ['volet' => 'projet']))
        ->assertOk()
        ->assertSeeInOrder(['Assistance Technique', 'Appuis Techniques', 'Alphabétisation familiale', 'Organiser deux ateliers'])
        ->assertSee('2 600 000')
        ->assertSee('600 000');
});

test("l'arborescence d'un point focal ne montre que sa direction", function () {
    $this->actingAs($this->focal)
        ->get(route('activities.tree', ['volet' => 'projet']))
        ->assertSee('Organiser deux ateliers')
        ->assertDontSee('Assurer la rémunération du personnel');
});

test('le filtre ILD / hors ILD', function () {
    $this->actingAs($this->coordination);

    Volt::test('activities.tree')
        ->set('ild', 'HORS_ILD')
        ->assertSee('Assurer la rémunération du personnel')
        ->assertDontSee('Organiser deux ateliers');
});

test('la fiche activité affiche le chemin dans le PTBA et le budget', function () {
    $activity = Activity::where('label', 'Organiser deux ateliers')->first();

    $this->actingAs($this->focal)
        ->get(route('activities.show', $activity))
        ->assertSeeInOrder(['C1', 'Assistance Technique', 'ILD 1'])
        ->assertSee('Budget planifié')
        ->assertSee('600 000');
});

test('le point focal modifie le budget de son activité, pas celui des autres', function () {
    $own = Activity::where('label', 'Organiser deux ateliers')->first();
    $other = Activity::where('label', 'Assurer la rémunération du personnel')->first();
    $this->actingAs($this->focal);

    Volt::test('activities.budget', ['activityId' => $own->id])
        ->call('create')
        ->set('label', 'Pause-café')
        ->set('quantity', 30)
        ->set('frequency', 2)
        ->set('unit_cost', 2500)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('budget-updated');

    expect($own->budgetLines()->sum('amount'))->toBe(600_000 + 150_000);

    Volt::test('activities.budget', ['activityId' => $other->id])->call('create')->assertForbidden();
});

test("le budget d'un exercice clos n'est plus modifiable", function () {
    $this->exercice->update(['status' => ExerciceStatus::Clos]);
    $own = Activity::where('label', 'Organiser deux ateliers')->first();

    $this->actingAs($this->focal);
    Volt::test('activities.budget', ['activityId' => $own->id])->call('create')->assertForbidden();
});
