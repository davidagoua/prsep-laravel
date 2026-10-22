<?php

use App\Enums\ReportType;
use App\Enums\Role;
use App\Finance\FinanceService;
use App\Imports\Ptba\PtbaAnalyzer;
use App\Imports\Ptba\PtbaImporter;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\User;
use App\Reporting\ObligationGenerator;
use App\Support\Dashboard;
use App\Workflow\TdrWorkflow;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowCircuitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PtbaFixtures;

beforeEach(function () {
    Storage::fake(TdrWorkflow::DISK);
    Notification::fake();
    $this->seed([RoleSeeder::class, WorkflowCircuitSeeder::class]);
    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    $this->daf = Direction::factory()->create(['code' => 'DAF']);
    $this->exercice = Exercice::factory()->current()->create(['year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $this->exercice->generatePeriodes();
    app(PtbaImporter::class)->import(app(PtbaAnalyzer::class)->analyze(PtbaFixtures::legacyProjet(), $this->exercice), $this->exercice);

    $role = fn (Role $r, ?Direction $d = null) => tap(User::factory()->create(['direction_id' => $d?->id]))->assignRole($r->value);
    $this->coordination = $role(Role::Coordination);
    $this->dg = $role(Role::DirecteurExecutif);
    $this->focal = $role(Role::PointFocal, $this->dspa);
    $this->directeur = $role(Role::DirecteurCentral, $this->dspa);

    $this->ateliers = Activity::where('label', 'Organiser deux ateliers')->first(); // DSPA, 600 000
    $finance = app(FinanceService::class);
    $finance->saveEngagement($this->ateliers, $this->coordination, ['amount' => 600_000, 'engaged_on' => '2025-03-01']);
    $finance->savePayment($this->ateliers, $this->coordination, ['amount' => 300_000, 'paid_on' => '2025-03-15']);
});

test('les indicateurs financiers et par direction sont exacts', function () {
    $d = app(Dashboard::class)->build($this->exercice, $this->dg);

    expect($d['finance']['total'])->toBe(['planned' => 2_600_000, 'engaged' => 600_000, 'paid' => 300_000])
        ->and($d['levels']['projet'][0])->toMatchArray(['code' => 'C1', 'planned' => 2_600_000, 'paid' => 300_000]);

    $dspa = collect($d['directions'])->firstWhere('code', 'DSPA');
    expect($dspa)->toMatchArray(['activities' => 1, 'planned' => 600_000, 'paid' => 300_000, 'tdr_approved' => 0]);
});

test('délai moyen de validation et TDR en retard', function () {
    $workflow = app(TdrWorkflow::class);
    $this->travelTo('2025-03-01 09:00');
    $tdr = $workflow->create($this->ateliers, $this->focal, 'TDR', UploadedFile::fake()->create('tdr.pdf', 10, 'application/pdf'));
    $workflow->submit($tdr, $this->focal);
    $this->travelTo('2025-03-02 09:00');
    $workflow->approve($tdr->fresh(), $this->directeur);
    $this->travelTo('2025-03-04 09:00');
    $workflow->approve($tdr->fresh(), $this->coordination, granted: true);

    $other = Activity::where('label', 'Assurer la rémunération du personnel')->first();
    $other->update(['direction_id' => $this->dspa->id]);
    $late = $workflow->create($other, $this->focal, 'TDR 2', UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'));
    $workflow->submit($late, $this->focal);
    $this->travelTo('2025-03-20 09:00');

    $d = app(Dashboard::class)->build($this->exercice, $this->dg);

    expect($d['tdr']['avg_days'])->toEqual(3.0)
        ->and($d['tdr']['in_review'])->toBe(1)
        ->and($d['tdr']['late'])->toBe(1)
        ->and($d['tdr']['by_step'])->toBe(['Direction centrale' => 1]);
});

test('rapports échus et remis', function () {
    app(ObligationGenerator::class)->generate($this->exercice, [ReportType::TrimestrielProjet], [$this->dspa->id, $this->daf->id], 10);
    $this->travelTo('2025-05-01');

    $d = app(Dashboard::class)->build($this->exercice, $this->dg);

    expect($d['reports'])->toMatchArray(['due' => 2, 'delivered' => 0, 'late' => 2, 'rate' => 0]);
});

test('la page est réservée aux rôles de pilotage', function () {
    $this->actingAs($this->dg)->get(route('pilotage'))
        ->assertOk()
        ->assertSee('Tableau de bord')
        ->assertSee('2 600 000')
        ->assertSee('Assistance Technique')
        ->assertSee('DSPA');

    $this->actingAs($this->focal)->get(route('pilotage'))->assertForbidden();
});
