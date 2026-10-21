<?php

use App\Enums\Role;
use App\Enums\TdrState;
use App\Enums\Volet;
use App\Enums\WorkflowSubject;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Tdr;
use App\Models\User;
use App\Models\WorkflowCircuit;
use App\Notifications\TdrNotification;
use App\Workflow\TdrWorkflow;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowCircuitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake(TdrWorkflow::DISK);
    $this->seed([RoleSeeder::class, WorkflowCircuitSeeder::class]);

    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    $this->daf = Direction::factory()->create(['code' => 'DAF']);
    $this->exercice = Exercice::factory()->current()->create();

    $role = fn (Role $r, ?Direction $d = null) => tap(User::factory()->create(['direction_id' => $d?->id]))->assignRole($r->value);
    $this->focal = $role(Role::PointFocal, $this->dspa);
    $this->directeur = $role(Role::DirecteurCentral, $this->dspa);
    $this->coordination = $role(Role::Coordination);

    $this->activity = Activity::factory()->create([
        'direction_id' => $this->dspa->id, 'exercice_id' => $this->exercice->id,
        'code' => 'A1.1.01', 'label' => 'Ateliers régionaux de formation', 'starts_on' => now()->addDays(5),
    ]);
    $this->autre = Activity::factory()->create([
        'direction_id' => $this->daf->id, 'exercice_id' => $this->exercice->id, 'label' => 'Audit financier annuel',
    ]);
});

function pdfUpload(): UploadedFile
{
    return UploadedFile::fake()->create('tdr.pdf', 50, 'application/pdf');
}

test('le point focal ne voit que les activités de sa direction', function () {
    $this->actingAs($this->focal)
        ->get(route('activities.index'))
        ->assertOk()
        ->assertSee('Ateliers régionaux de formation')
        ->assertDontSee('Audit financier annuel');

    $this->actingAs($this->coordination)
        ->get(route('activities.index'))
        ->assertSee('Audit financier annuel');
});

test("la fiche d'une autre direction est interdite au point focal", function () {
    $this->actingAs($this->focal)->get(route('activities.show', $this->autre))->assertForbidden();
    $this->actingAs($this->focal)->get(route('activities.show', $this->activity))->assertOk()->assertSee('Déposer le TDR');
});

test('parcours complet depuis le panneau TDR', function () {
    Notification::fake();
    $this->actingAs($this->focal);

    Volt::test('tdr.panel', ['activityId' => $this->activity->id])
        ->set('title', 'TDR ateliers')
        ->set('file', pdfUpload())
        ->call('deposit')
        ->assertHasNoErrors()
        ->call('submit')
        ->assertDispatched('tdr-updated');

    $tdr = $this->activity->fresh()->tdr;
    expect($tdr->state)->toBe(TdrState::InReview);

    $this->actingAs($this->directeur);
    Volt::test('tdr.panel', ['activityId' => $this->activity->id, 'reviewMode' => true])
        ->assertSee('Votre décision est attendue')
        ->set('comment', '')
        ->call('requestChanges')
        ->assertHasErrors('comment')
        ->set('comment', 'Préciser le calendrier')
        ->call('requestChanges')
        ->assertDispatched('tdr-decided');

    $this->actingAs($this->focal);
    Volt::test('tdr.panel', ['activityId' => $this->activity->id])
        ->assertSee('Préciser le calendrier')
        ->set('file', pdfUpload())
        ->call('uploadVersion')
        ->call('submit');

    expect($tdr->fresh()->currentStep->label)->toBe('Direction centrale');

    $this->actingAs($this->directeur);
    Volt::test('tdr.panel', ['activityId' => $this->activity->id])->call('approve');

    $this->actingAs($this->coordination);
    Volt::test('tdr.panel', ['activityId' => $this->activity->id])
        ->call('approve')
        ->assertHasErrors('granted')
        ->set('granted', '1')
        ->set('injunction', true)
        ->call('approve')
        ->assertHasNoErrors();

    expect($tdr->fresh())->state->toBe(TdrState::Approved)->injunction->toBeTrue();
});

test('une action interdite affiche une erreur sans rien modifier', function () {
    $tdr = app(TdrWorkflow::class)->create($this->activity, $this->focal, 'TDR', pdfUpload());

    $this->actingAs($this->directeur);
    Volt::test('tdr.panel', ['activityId' => $this->activity->id])->call('submit');

    expect($tdr->fresh()->state)->toBe(TdrState::Draft);
});

test('« À traiter » regroupe les dossiers par action attendue', function () {
    Notification::fake();
    $workflow = app(TdrWorkflow::class);
    $tdr = $workflow->create($this->activity, $this->focal, 'TDR', pdfUpload());
    $workflow->submit($tdr, $this->focal);

    $this->actingAs($this->directeur)->get(route('inbox'))
        ->assertSee('À valider')
        ->assertSee('Ateliers régionaux de formation');

    $workflow->requestChanges($tdr->fresh(), $this->directeur, 'À revoir');

    $this->actingAs($this->focal)->get(route('inbox'))
        ->assertSee('À corriger')
        ->assertSee('À revoir');

    $this->actingAs($this->directeur)->get(route('inbox'))->assertSee('Rien à traiter');
});

test('les activités sans TDR proches du démarrage sont signalées au point focal', function () {
    $this->actingAs($this->focal)->get(route('inbox'))
        ->assertSee('TDR à préparer')
        ->assertSee('Ateliers régionaux de formation');
});

test('la page Validations enchaîne sur le dossier suivant après une décision', function () {
    Notification::fake();
    $workflow = app(TdrWorkflow::class);
    $second = Activity::factory()->create(['direction_id' => $this->dspa->id, 'exercice_id' => $this->exercice->id]);

    foreach ([$this->activity, $second] as $activity) {
        $tdr = $workflow->create($activity, $this->focal, 'TDR', pdfUpload());
        $workflow->submit($tdr, $this->focal);
    }
    [$premier, $suivant] = Tdr::orderBy('id')->get();

    $this->actingAs($this->directeur);
    $page = Volt::test('validations')->assertSet('selectedId', $premier->id);

    $workflow->approve($premier->fresh(), $this->directeur);
    $page->dispatch('tdr-decided')->assertSet('selectedId', $suivant->id);
});

test('le menu affiche le nombre de dossiers en attente', function () {
    Notification::fake();
    $workflow = app(TdrWorkflow::class);
    $workflow->submit($workflow->create($this->activity, $this->focal, 'TDR', pdfUpload()), $this->focal);

    $this->actingAs($this->directeur)->get(route('dashboard'))->assertSeeInOrder(['Validations', '1']);
});

test('le téléchargement du TDR respecte la visibilité', function () {
    $tdr = app(TdrWorkflow::class)->create($this->activity, $this->focal, 'TDR', pdfUpload());
    $document = $tdr->document;

    $this->actingAs($this->focal)->get(route('tdr-documents.show', $document))->assertOk();
    $this->actingAs($this->coordination)->get(route('tdr-documents.show', [$document, 'apercu' => 1]))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename=tdr.pdf');

    $autreFocal = tap(User::factory()->create(['direction_id' => $this->daf->id]))->assignRole(Role::PointFocal->value);
    $this->actingAs($autreFocal)->get(route('tdr-documents.show', $document))->assertForbidden();
});

test('les notifications s\'affichent et se marquent comme lues', function () {
    $tdr = app(TdrWorkflow::class)->create($this->activity, $this->focal, 'TDR', pdfUpload());
    $this->directeur->notify(new TdrNotification($tdr, TdrNotification::ACTION_REQUIRED, 'Direction centrale'));

    $this->actingAs($this->directeur);
    $notification = $this->directeur->notifications()->first();

    Volt::test('notifications.bell')
        ->assertSee('TDR à valider')
        ->call('open', $notification->id)
        ->assertRedirect(route('validations', ['tdr' => $tdr->id]));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test("l'admin modifie un circuit depuis l'écran dédié", function () {
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
    $this->actingAs($admin)->get(route('admin.circuits'))->assertOk()->assertSee('Comité technique');

    Volt::test('admin.circuits')
        ->call('edit', 'projet')
        ->call('addStep')
        ->set('steps.2.label', 'Direction exécutive')
        ->set('steps.2.role', Role::DirecteurExecutif->value)
        ->call('save')
        ->assertHasNoErrors();

    $circuit = WorkflowCircuit::activeFor(WorkflowSubject::Tdr, Volet::Projet);
    expect($circuit->steps->pluck('label')->all())->toBe(['Direction centrale', 'Coordination', 'Direction exécutive']);

    $this->actingAs($this->coordination)->get(route('admin.circuits'))->assertForbidden();
});
