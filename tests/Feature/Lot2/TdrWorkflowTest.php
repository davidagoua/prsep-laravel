<?php

use App\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Enums\StepScope;
use App\Enums\TdrState;
use App\Enums\Volet;
use App\Enums\WorkflowAction;
use App\Enums\WorkflowSubject;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Tdr;
use App\Models\User;
use App\Models\WorkflowCircuit;
use App\Notifications\TdrNotification;
use App\Workflow\CircuitPublisher;
use App\Workflow\TdrWorkflow;
use App\Workflow\WorkflowException;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowCircuitSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(TdrWorkflow::DISK);
    Notification::fake();
    $this->seed([RoleSeeder::class, WorkflowCircuitSeeder::class]);

    $this->workflow = app(TdrWorkflow::class);
    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    $this->daf = Direction::factory()->create(['code' => 'DAF']);
    $exercice = Exercice::factory()->current()->create();

    $this->focal = makeUser(Role::PointFocal, $this->dspa);
    $this->directeur = makeUser(Role::DirecteurCentral, $this->dspa);
    $this->directeurDaf = makeUser(Role::DirecteurCentral, $this->daf);
    $this->coordination = makeUser(Role::Coordination);
    $this->dg = makeUser(Role::DirecteurExecutif);

    $this->projet = Activity::factory()->create(['direction_id' => $this->dspa->id, 'exercice_id' => $exercice->id]);
    $this->programme = Activity::factory()->programme()->create(['direction_id' => $this->dspa->id, 'exercice_id' => $exercice->id]);
});

function makeUser(Role $role, ?Direction $direction = null): User
{
    return tap(User::factory()->create(['direction_id' => $direction?->id]))->assignRole($role->value);
}

function tdrFile(string $name = 'tdr.docx'): UploadedFile
{
    return UploadedFile::fake()->create($name, 120, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}

test('le point focal dépose un TDR en brouillon avec sa première version', function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR atelier', tdrFile());

    expect($tdr->state)->toBe(TdrState::Draft)
        ->and($tdr->document->version)->toBe(1)
        ->and($tdr->transitions()->first()->action)->toBe(WorkflowAction::Created);
    Storage::disk(TdrWorkflow::DISK)->assertExists($tdr->document->path);
});

test('seul un point focal de la direction peut déposer', function () {
    $autre = makeUser(Role::PointFocal, $this->daf);

    $this->workflow->create($this->projet, $autre, 'TDR', tdrFile());
})->throws(AuthorizationException::class);

test('un seul TDR vivant par activité', function () {
    $this->workflow->create($this->projet, $this->focal, 'TDR 1', tdrFile());
    $this->workflow->create($this->projet, $this->focal, 'TDR 2', tdrFile());
})->throws(WorkflowException::class);

test('circuit projet complet : direction centrale puis coordination avec avis', function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);

    expect($tdr->fresh()->state)->toBe(TdrState::InReview)
        ->and($tdr->fresh()->currentStep->label)->toBe('Direction centrale');
    Notification::assertSentTo($this->directeur, TdrNotification::class, fn ($n) => $n->kind === TdrNotification::ACTION_REQUIRED);
    Notification::assertNotSentTo($this->directeurDaf, TdrNotification::class);

    $this->workflow->approve($tdr->fresh(), $this->directeur);
    expect($tdr->fresh()->currentStep->label)->toBe('Coordination');
    Notification::assertSentTo($this->coordination, TdrNotification::class);

    $this->workflow->approve($tdr->fresh(), $this->coordination, 'Bon pour exécution', granted: true, injunction: false);

    expect($tdr->fresh())
        ->state->toBe(TdrState::Approved)
        ->coordination_granted->toBeTrue()
        ->injunction->toBeFalse()
        ->approved_at->not->toBeNull();
    Notification::assertSentTo($this->focal, TdrNotification::class, fn ($n) => $n->kind === TdrNotification::APPROVED);
});

test("l'avis de la coordination est obligatoire", function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);
    $this->workflow->approve($tdr->fresh(), $this->directeur);

    $this->workflow->approve($tdr->fresh(), $this->coordination);
})->throws(WorkflowException::class, 'accordé');

test('le circuit programme passe par la direction exécutive', function () {
    $tdr = $this->workflow->create($this->programme, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);
    $this->workflow->approve($tdr->fresh(), $this->directeur);
    $this->workflow->approve($tdr->fresh(), $this->coordination, granted: true);

    expect($tdr->fresh()->currentStep->label)->toBe('Direction exécutive');

    $this->workflow->approve($tdr->fresh(), $this->dg);
    expect($tdr->fresh()->state)->toBe(TdrState::Approved);
});

test("un directeur d'une autre direction ne peut pas décider", function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);

    $this->workflow->approve($tdr->fresh(), $this->directeurDaf);
})->throws(AuthorizationException::class);

test("un valideur ne peut pas décider à l'étape d'un autre", function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);

    $this->workflow->approve($tdr->fresh(), $this->coordination, granted: true);
})->throws(AuthorizationException::class);

test("après un renvoi, le TDR reprend à l'étape qui l'a renvoyé", function () {
    $tdr = $this->workflow->create($this->programme, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);
    $this->workflow->approve($tdr->fresh(), $this->directeur);

    $this->workflow->requestChanges($tdr->fresh(), $this->coordination, 'Revoir le budget');

    $tdr = $tdr->fresh();
    expect($tdr->state)->toBe(TdrState::ChangesRequested)
        ->and($tdr->returnedFromStep->label)->toBe('Comité technique')
        ->and($tdr->current_step_id)->toBeNull();
    Notification::assertSentTo($this->focal, TdrNotification::class, fn ($n) => $n->kind === TdrNotification::RETURNED && $n->comment === 'Revoir le budget');

    $this->workflow->replaceDocument($tdr, $this->focal, tdrFile('tdr-v2.docx'), 'Budget révisé');
    $this->workflow->submit($tdr->fresh(), $this->focal);

    $tdr = $tdr->fresh();
    expect($tdr->state)->toBe(TdrState::InReview)
        ->and($tdr->currentStep->label)->toBe('Comité technique')
        ->and($tdr->document->version)->toBe(2)
        ->and($tdr->transitions()->first()->action)->toBe(WorkflowAction::Resubmitted);
});

test('le renvoi exige un commentaire', function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);

    $this->workflow->requestChanges($tdr->fresh(), $this->directeur, '   ');
})->throws(WorkflowException::class);

test("un TDR en validation n'est plus modifiable par le point focal", function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);

    $this->workflow->replaceDocument($tdr->fresh(), $this->focal, tdrFile());
})->throws(AuthorizationException::class);

test("exécution puis clôture de l'activité", function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);
    $this->workflow->approve($tdr->fresh(), $this->directeur);
    $this->workflow->approve($tdr->fresh(), $this->coordination, granted: true);

    $this->workflow->start($tdr->fresh(), $this->focal);
    expect($this->projet->fresh()->execution_status)->toBe(ExecutionStatus::EnCours);

    $this->workflow->close($tdr->fresh(), $this->focal, tdrFile('rapport-final.docx'), [
        'lessons' => 'Prévoir la logistique plus tôt.',
        'risks' => null,
        'recommendations' => 'Mutualiser les ateliers.',
    ]);

    expect($tdr->fresh())
        ->state->toBe(TdrState::Closed)
        ->finalDocument->not->toBeNull()
        ->lessons->toBe('Prévoir la logistique plus tôt.');
    expect($this->projet->fresh()->execution_status)->toBe(ExecutionStatus::Terminee);
});

test('abandonner un brouillon libère l\'activité pour un nouveau TDR', function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->abandon($tdr, $this->focal);

    $nouveau = $this->workflow->create($this->projet->fresh(), $this->focal, 'TDR bis', tdrFile());

    expect($this->projet->fresh()->tdr->is($nouveau))->toBeTrue();
});

test('la file des décisions ne montre que les dossiers de l\'utilisateur', function () {
    $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
    $this->workflow->submit($tdr, $this->focal);

    expect(Tdr::awaitingDecisionBy($this->directeur)->pluck('id')->all())->toBe([$tdr->id])
        ->and(Tdr::awaitingDecisionBy($this->directeurDaf)->count())->toBe(0)
        ->and(Tdr::awaitingDecisionBy($this->coordination)->count())->toBe(0);

    $this->workflow->approve($tdr->fresh(), $this->directeur);

    expect(Tdr::awaitingDecisionBy($this->directeur)->count())->toBe(0)
        ->and(Tdr::awaitingDecisionBy($this->coordination)->count())->toBe(1);
});

describe('circuits paramétrables', function () {
    test('modifier un circuit inutilisé le met à jour sans nouvelle version', function () {
        $circuit = app(CircuitPublisher::class)->publish(WorkflowSubject::Tdr, Volet::Projet, 'Projet', [
            ['label' => 'Coordination', 'role' => Role::Coordination->value, 'scope' => StepScope::Global->value, 'gives_opinion' => true],
        ]);

        expect($circuit->version)->toBe(1)->and($circuit->steps)->toHaveCount(1);
    });

    test('modifier un circuit utilisé crée une version ; les dossiers en cours gardent l\'ancienne', function () {
        $tdr = $this->workflow->create($this->projet, $this->focal, 'TDR', tdrFile());
        $this->workflow->submit($tdr, $this->focal);
        $ancien = $tdr->fresh()->circuit;

        $nouveau = app(CircuitPublisher::class)->publish(WorkflowSubject::Tdr, Volet::Projet, 'Projet v2', [
            ['label' => 'Direction centrale', 'role' => Role::DirecteurCentral->value, 'scope' => StepScope::Direction->value, 'gives_opinion' => false],
            ['label' => 'Coordination', 'role' => Role::Coordination->value, 'scope' => StepScope::Global->value, 'gives_opinion' => true],
            ['label' => 'Direction exécutive', 'role' => Role::DirecteurExecutif->value, 'scope' => StepScope::Global->value, 'gives_opinion' => false],
        ]);

        expect($nouveau->version)->toBe(2)
            ->and($ancien->fresh()->is_active)->toBeFalse()
            ->and(WorkflowCircuit::activeFor(WorkflowSubject::Tdr, Volet::Projet)->is($nouveau))->toBeTrue();

        // Le dossier en cours termine sur l'ancien circuit (2 étapes).
        $this->workflow->approve($tdr->fresh(), $this->directeur);
        $this->workflow->approve($tdr->fresh(), $this->coordination, granted: true);
        expect($tdr->fresh()->state)->toBe(TdrState::Approved);

        // Un nouveau dossier suit le nouveau circuit (3 étapes).
        $autre = $this->workflow->create(Activity::factory()->create(['direction_id' => $this->dspa->id]), $this->focal, 'TDR 2', tdrFile());
        $this->workflow->submit($autre, $this->focal);
        expect($autre->fresh()->circuit->steps)->toHaveCount(3);
    });
});
