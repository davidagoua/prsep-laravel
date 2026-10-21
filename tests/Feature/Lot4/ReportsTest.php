<?php

use App\Collabora\WopiFile;
use App\Collabora\WopiToken;
use App\Enums\ReportState;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Report;
use App\Models\ReportObligation;
use App\Models\User;
use App\Notifications\ReportNotification;
use App\Reporting\ObligationGenerator;
use App\Reporting\ReportWorkflow;
use App\Workflow\WorkflowException;
use Database\Seeders\RoleSeeder;
use Database\Seeders\Support\DemoDocx;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake(ReportWorkflow::DISK);
    Notification::fake();
    // Collabora simulé : un seul format pris en charge, docx.
    Http::fake(['*/hosting/discovery' => Http::response(
        '<wopi-discovery><net-zone><app name="writer"><action ext="docx" name="edit" urlsrc="http://collabora:9980/browser/abc/cool.html?"/></app></net-zone></wopi-discovery>'
    )]);
    cache()->forget('collabora.discovery');

    $this->seed(RoleSeeder::class);
    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    $this->daf = Direction::factory()->create(['code' => 'DAF']);
    $this->exercice = Exercice::factory()->current()->create(['year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->exercice->generatePeriodes();
    $this->mars = $this->exercice->periodes()->where('type', 'mois')->where('number', 3)->first();

    $role = fn (Role $r, ?Direction $d = null) => tap(User::factory()->create(['direction_id' => $d?->id]))->assignRole($r->value);
    $this->focal = $role(Role::PointFocal, $this->dspa);
    $this->directeur = $role(Role::DirecteurCentral, $this->dspa);
    $this->focalDaf = $role(Role::PointFocal, $this->daf);
    $this->coordination = $role(Role::Coordination);
    $this->dg = $role(Role::DirecteurExecutif);

    $this->workflow = app(ReportWorkflow::class);
});

function fiche(?User $user = null): Report
{
    return app(ReportWorkflow::class)->create(test()->exercice, ReportType::MensuelProjet, test()->mars, test()->dspa, false,
        $user ?? test()->focal, DemoDocx::make('Fiche mensuelle', ['Activités du mois']));
}

describe('rapports attendus', function () {
    test('génération par type, période et direction, sans doublon', function () {
        $generator = app(ObligationGenerator::class);
        $count = $generator->generate($this->exercice, [ReportType::MensuelProjet, ReportType::TrimestrielProjet], [$this->dspa->id, $this->daf->id], 10);

        expect($count)->toBe((12 + 4) * 2)
            ->and(ReportObligation::where('type', 'mensuel_projet')->where('periode_id', $this->mars->id)->first()->due_on->toDateString())->toBe('2026-04-10')
            ->and($generator->generate($this->exercice, [ReportType::MensuelProjet], [$this->dspa->id], 10))->toBe(0);
    });

    test('les périodes terminées avant la date de départ sont ignorées', function () {
        $count = app(ObligationGenerator::class)->generate($this->exercice, [ReportType::MensuelProjet, ReportType::TrimestrielProjet], [$this->dspa->id], 10, Carbon::parse('2026-10-01'));

        expect($count)->toBe(3 + 1); // octobre, novembre, décembre + T4
    });

    test('statut : attendu, en retard, remis', function () {
        $o = ReportObligation::create(['exercice_id' => $this->exercice->id, 'type' => ReportType::MensuelProjet, 'periode_id' => $this->mars->id, 'direction_id' => $this->dspa->id, 'due_on' => today()->addDays(3)]);
        expect(ReportObligation::status(null, $o)['key'])->toBe('expected');

        $o->due_on = today()->subDay();
        expect(ReportObligation::status(null, $o)['key'])->toBe('late');

        $report = fiche();
        $this->workflow->submit($report, $this->focal);
        expect(ReportObligation::status($report->fresh(), $o)['key'])->toBe('submitted');
    });
});

describe('circuit', function () {
    test('dépôt, remise, renvoi, correction, validation', function () {
        $report = fiche();
        expect($report->title)->toBe('Fiche mensuelle — projet · Mars 2026 · DSPA')
            ->and($report->state)->toBe(ReportState::Draft);

        $this->workflow->submit($report, $this->focal);
        Notification::assertSentTo($this->coordination, ReportNotification::class, fn ($n) => $n->kind === ReportNotification::SUBMITTED);

        $this->workflow->requestChanges($report->fresh(), $this->coordination, 'Ajouter les difficultés rencontrées');
        Notification::assertSentTo([$this->focal, $this->directeur], ReportNotification::class, fn ($n) => $n->kind === ReportNotification::RETURNED);
        Notification::assertNotSentTo($this->focalDaf, ReportNotification::class);

        $this->workflow->replaceDocument($report->fresh(), $this->directeur, DemoDocx::make('Fiche v2'));
        $this->workflow->submit($report->fresh(), $this->directeur);
        $this->workflow->accept($report->fresh(), $this->coordination);

        expect($report->fresh())->state->toBe(ReportState::Accepted)->and($report->documents()->count())->toBe(2);
    });

    test('un seul rapport par période et direction', function () {
        fiche();
        fiche();
    })->throws(WorkflowException::class);

    test('une autre direction ne peut pas déposer pour DSPA', function () {
        fiche($this->focalDaf);
    })->throws(AuthorizationException::class);

    test('un rapport remis n\'est plus modifiable', function () {
        $report = fiche();
        $this->workflow->submit($report, $this->focal);

        $this->workflow->replaceDocument($report->fresh(), $this->focal, DemoDocx::make('x'));
    })->throws(AuthorizationException::class);

    test('le rapport consolidé est publié à un public choisi', function () {
        $consolide = $this->workflow->create($this->exercice, ReportType::MensuelProjet, $this->mars, null, true, $this->coordination, DemoDocx::make('Consolidé'));
        expect($consolide->title)->toBe('Fiche mensuelle — projet · Mars 2026 · Consolidé');

        expect(Report::visibleTo($this->focalDaf)->whereKey($consolide->id)->exists())->toBeFalse();

        $this->workflow->publish($consolide, $this->coordination, [Role::DirecteurExecutif->value], [$this->daf->id]);

        expect($consolide->fresh()->state)->toBe(ReportState::Published)
            ->and(Report::visibleTo($this->focalDaf)->whereKey($consolide->id)->exists())->toBeTrue()
            ->and(Report::visibleTo($this->focal)->whereKey($consolide->id)->exists())->toBeFalse();
        Notification::assertSentTo([$this->dg, $this->focalDaf], ReportNotification::class, fn ($n) => $n->kind === ReportNotification::PUBLISHED);
    });
});

describe('Collabora / WOPI', function () {
    test('l\'éditeur ouvre Collabora en écriture pour qui peut modifier', function () {
        $report = fiche();
        $fileId = WopiFile::idFor($report->document);

        $this->actingAs($this->focal)->get(route('documents.editor', $fileId))
            ->assertOk()
            ->assertSee('http://localhost:9980/browser/abc/cool.html?WOPISrc=', false)
            ->assertSee('modification en ligne');

        $this->workflow->submit($report, $this->focal);
        $this->actingAs($this->coordination)->get(route('documents.editor', $fileId))->assertOk()->assertSee('lecture seule');
        $this->actingAs($this->focalDaf)->get(route('documents.editor', $fileId))->assertForbidden();
    });

    test('CheckFileInfo, GetFile et PutFile avec un jeton valide', function () {
        $report = fiche();
        $fileId = WopiFile::idFor($report->document);
        $token = WopiToken::issue($fileId, $this->focal->id, true)['token'];

        $this->getJson("/wopi/files/{$fileId}?access_token={$token}")
            ->assertOk()
            ->assertJson(['BaseFileName' => 'rapport.docx', 'UserCanWrite' => true, 'UserFriendlyName' => $this->focal->name]);

        $original = $this->get("/wopi/files/{$fileId}/contents?access_token={$token}")->assertOk()->getContent();
        expect(substr($original, 0, 2))->toBe('PK');

        $this->call('POST', "/wopi/files/{$fileId}/contents?access_token={$token}", [], [], [], ['HTTP_X-WOPI-Override' => 'PUT'], 'NOUVEAU CONTENU')
            ->assertOk();
        expect(Storage::disk(ReportWorkflow::DISK)->get($report->document->fresh()->path))->toBe('NOUVEAU CONTENU');
    });

    test('jeton invalide, expiré, pour un autre fichier ou en lecture seule', function () {
        $report = fiche();
        $fileId = WopiFile::idFor($report->document);

        $this->getJson("/wopi/files/{$fileId}?access_token=faux.jeton")->assertUnauthorized();

        $other = WopiToken::issue('report-999', $this->focal->id, true)['token'];
        $this->getJson("/wopi/files/{$fileId}?access_token={$other}")->assertUnauthorized();

        $readOnly = WopiToken::issue($fileId, $this->focal->id, false)['token'];
        $this->call('POST', "/wopi/files/{$fileId}/contents?access_token={$readOnly}", [], [], [], [], 'X')->assertUnauthorized();

        $expiring = WopiToken::issue($fileId, $this->focal->id, true)['token'];
        $this->travel(WopiToken::TTL_SECONDS + 60)->seconds();
        $this->getJson("/wopi/files/{$fileId}?access_token={$expiring}")->assertUnauthorized();
    });

    test('après remise, l\'écriture est refusée même avec un jeton d\'écriture', function () {
        $report = fiche();
        $fileId = WopiFile::idFor($report->document);
        $token = WopiToken::issue($fileId, $this->focal->id, true)['token'];
        $this->workflow->submit($report, $this->focal);

        $this->getJson("/wopi/files/{$fileId}?access_token={$token}")->assertJson(['UserCanWrite' => false]);
        $this->call('POST', "/wopi/files/{$fileId}/contents?access_token={$token}", [], [], [], [], 'X')->assertUnauthorized();
    });
});

describe('écrans', function () {
    beforeEach(function () {
        app(ObligationGenerator::class)->generate($this->exercice, [ReportType::MensuelProjet], [$this->dspa->id, $this->daf->id], 10);
    });

    test('le point focal voit ses rapports à remettre et dépose depuis la liste', function () {
        $this->actingAs($this->focal);

        Volt::test('reports.index')
            ->assertSet('vue', 'remettre')
            ->assertSee('Mars 2026')
            ->call('openDeposit', 'mensuel_projet', $this->mars->id)
            ->set('d_file', UploadedFile::fake()->createWithContent('fiche.docx', file_get_contents(DemoDocx::make('Fiche')->getPathname())))
            ->call('deposit')
            ->assertHasNoErrors()
            ->assertRedirect(route('reports.show', Report::first()));
    });

    test('la coordination voit le calendrier et consolide une période', function () {
        $this->workflow->submit(fiche(), $this->focal);
        $this->actingAs($this->coordination);

        Volt::test('reports.index')->assertSet('vue', 'calendrier')->assertSee('DSPA')->assertSee('DAF')->assertSee('Soumis');

        $this->get(route('reports.period', ['type' => 'mensuel_projet', 'periode' => $this->mars->id]))
            ->assertOk()
            ->assertSee('1 rapport(s) remis sur 2 attendu(s)');
    });

    test('la page du rapport propose la validation à la coordination', function () {
        $report = fiche();
        $this->workflow->submit($report, $this->focal);
        $this->actingAs($this->coordination);

        Volt::test('reports.show', ['report' => $report])
            ->assertSee('Ce rapport attend votre validation')
            ->set('comment', '')
            ->call('requestChanges')
            ->assertHasErrors('comment')
            ->call('accept');

        expect($report->fresh()->state)->toBe(ReportState::Accepted);
    });

    test('« À traiter » et le menu signalent les rapports', function () {
        $report = fiche();
        $this->workflow->submit($report, $this->focal);

        $this->actingAs($this->coordination)->get(route('inbox'))->assertSee('Rapports à valider')->assertSee($report->title);

        $this->travelTo('2026-05-01');
        $this->actingAs($this->focalDaf)->get(route('inbox'))->assertSee('Rapports à remettre')->assertSee('En retard');
    });

    test('un point focal ne voit pas les rapports d\'une autre direction', function () {
        $report = fiche();

        $this->actingAs($this->focalDaf)->get(route('reports.show', $report))->assertForbidden();
        $this->actingAs($this->focalDaf)->get(route('report-documents.show', $report->document))->assertForbidden();
    });
});
