<?php

use App\Enums\Role;
use App\Imports\Ptba\PtbaAnalyzer;
use App\Imports\Ptba\PtbaImporter;
use App\Models\Activity;
use App\Models\BudgetLine;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\ProgrammeAction;
use App\Models\ProjectIndicator;
use App\Models\PtbaImport;
use App\Models\ResultSubdomain;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\PtbaFixtures;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    $this->daf = Direction::factory()->create(['code' => 'DAF']);
    $this->exercice = Exercice::factory()->current()->create(['year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $this->analyzer = app(PtbaAnalyzer::class);
});

describe('modèle SIGPRO', function () {
    test('lit la hiérarchie, les activités et les lignes de coût', function () {
        $parsed = $this->analyzer->analyze(PtbaFixtures::sigproProjet(), $this->exercice);

        expect($parsed->format)->toBe('sigpro')
            ->and($parsed->activities)->toHaveCount(2)
            ->and($parsed->activities['projet:A1']['lines'])->toHaveCount(2)
            ->and($parsed->activities['projet:A1']['months'])->toBe([3, 4])
            ->and($parsed->hasErrors())->toBeFalse();

        $summary = $this->analyzer->summarize($parsed, $this->exercice);
        expect($summary['total'])->toBe(10 * 2 * 50000 + 300000 + 1000000)
            ->and($summary['new_activities'])->toBe(2);
    });

    test('signale une structure inconnue sans bloquer', function () {
        $parsed = $this->analyzer->analyze(PtbaFixtures::sigproProjet(), $this->exercice);

        expect(collect($parsed->issues)->where('level', 'warning')->pluck('message')->implode(' '))->toContain('INCONNUE');
    });

    test('bloque sur une valeur non numérique', function () {
        $parsed = $this->analyzer->analyze(PtbaFixtures::sigproProjet(['A2' => ['unit_cost' => 'beaucoup']]), $this->exercice);

        expect($parsed->hasErrors())->toBeTrue()
            ->and(collect($parsed->issues)->firstWhere('level', 'error')['row'])->toBe(4);
    });

    test('importe puis réimporte sans doublon, en remplaçant les lignes', function () {
        $importer = app(PtbaImporter::class);
        $importer->import($this->analyzer->analyze(PtbaFixtures::sigproProjet(), $this->exercice), $this->exercice);

        $a1 = Activity::where('code', 'A1')->first();
        expect($a1->direction_id)->toBe($this->dspa->id)
            ->and($a1->parent)->toBeInstanceOf(ProjectIndicator::class)
            ->and($a1->parent->isIld())->toBeTrue()
            ->and($a1->budgetLines()->sum('amount'))->toBe(1_300_000)
            ->and(Activity::where('code', 'A2')->first()->parent->type)->toBe(ProjectIndicator::HORS_ILD);

        $stats = $importer->import($this->analyzer->analyze(PtbaFixtures::sigproProjet(['A1' => ['quantity' => 20]]), $this->exercice), $this->exercice);

        expect($stats)->toMatchArray(['created' => 0, 'updated' => 2])
            ->and(Activity::count())->toBe(2)
            ->and($a1->fresh()->budgetLines()->sum('amount'))->toBe(20 * 2 * 50000 + 300000);
    });
});

describe('formats historiques', function () {
    test('PTBA projet : niveaux typés, tâches à la place du coût de l\'activité', function () {
        $parsed = $this->analyzer->analyze(PtbaFixtures::legacyProjet(), $this->exercice);

        expect($parsed->format)->toBe('legacy_projet')
            ->and($parsed->hasErrors())->toBeFalse()
            ->and(collect($parsed->nodes)->pluck('type')->countBy()->all())->toMatchArray(['project_component' => 1, 'project_subcomponent' => 1, 'project_indicator' => 2])
            ->and($parsed->activities)->toHaveCount(2);

        $remuneration = collect($parsed->activities)->firstWhere('label', 'Assurer la rémunération du personnel');
        expect(collect($remuneration['lines'])->pluck('label')->all())->toBe(['Contrat du coordonnateur', 'Contrat du comptable']);

        $summary = $this->analyzer->summarize($parsed, $this->exercice);
        expect($summary['total'])->toBe(2_600_000);
    });

    test('PTBA programme : cadre de résultats, sous-activités, chronogramme et coûts', function () {
        $parsed = $this->analyzer->analyze(PtbaFixtures::legacyProgramme(), $this->exercice);

        expect($parsed->format)->toBe('legacy_programme')
            ->and($parsed->year)->toBe(2025)
            ->and($parsed->hasErrors())->toBeFalse()
            ->and(collect($parsed->activities)->pluck('code')->all())->toBe(['1.2-1.1.1', '1.2-1.1.1.1', '1.2-1.1.2']);

        $normes = $parsed->activities['programme:1.2-1.1.1'];
        $cabinet = $parsed->activities['programme:1.2-1.1.1.1'];
        $formation = $parsed->activities['programme:1.2-1.1.2'];

        expect($normes['months'])->toBe([2, 3])
            ->and($normes['direction'])->toBe('DSPA')
            ->and($cabinet['parent_activity'])->toBe('programme:1.2-1.1.1')
            ->and($cabinet['lines'])->toHaveCount(2)
            ->and($cabinet['lines'][1]['unit_cost'])->toEqual(300_000) // coût total retenu (colonnes détournées)
            ->and(collect($formation['lines'])->pluck('label')->all())->toBe([
                'Tâche 1.1.2.1 : Perdiem des participants — Axe 1 : Bouaké',
                'Tâche 1.1.2.1 : Perdiem des participants — Axe 2 : Korhogo',
            ]);

        expect($parsed->nodes['result_subdomain:1.2']['extra']['indicators'][0]['label'])->toBe('Nombre de salles construites');

        // 9 000 000 + 300 000 + 3 000 000 = 12 300 000 : cohérent avec le total inscrit.
        expect(collect($parsed->issues)->filter(fn ($i) => str_contains($i['message'], 'inscrits'))->count())->toBe(0);

        app(PtbaImporter::class)->import($parsed, $this->exercice);
        $cabinetModel = Activity::where('code', '1.2-1.1.1.1')->first();
        expect($cabinetModel->parentActivity->code)->toBe('1.2-1.1.1')
            ->and($cabinetModel->parent)->toBeInstanceOf(ProgrammeAction::class)
            ->and(ResultSubdomain::where('code', '1.2')->first()->indicators)->toHaveCount(1);
    });

    test('un écart avec les totaux inscrits est signalé', function () {
        $path = PtbaFixtures::legacyProgramme();
        $book = IOFactory::load($path);
        $book->getActiveSheet()->setCellValue('AF8', 50_000_000); // composante annoncée à 50 M
        (new Xlsx($book))->save($path);

        $parsed = $this->analyzer->analyze($path, $this->exercice);

        expect(collect($parsed->issues)->pluck('message')->implode(' '))->toContain('inscrits dans le fichier');
    });

    test('un fichier inconnu est refusé', function () {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Nom', 'Prénom'], ['Koné', 'Awa']]);
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        (new Xlsx($book))->save($path);

        expect($this->analyzer->analyze($path, $this->exercice)->hasErrors())->toBeTrue();
    });
});

describe('assistant', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->coordination = tap(User::factory()->create())->assignRole(Role::Coordination->value);
    });

    test('analyse, prévisualisation puis import validé', function () {
        $this->actingAs($this->coordination);
        $file = UploadedFile::fake()->createWithContent('ptba-2025.xlsx', file_get_contents(PtbaFixtures::legacyProjet()));

        $page = Volt::test('ptba.import')
            ->set('file', $file)
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSee('PTBA projet (format historique)')
            ->assertSee('Assistance Technique');

        $import = PtbaImport::first();
        expect($import->status)->toBe(PtbaImport::ANALYSED)
            ->and(Activity::count())->toBe(0);

        $page->call('confirm');

        expect($import->fresh()->status)->toBe(PtbaImport::DONE)
            ->and(Activity::count())->toBe(2)
            ->and(BudgetLine::sum('amount'))->toEqual(2_600_000);
    });

    test('un import avec erreurs ne peut pas être validé', function () {
        $this->actingAs($this->coordination);
        $file = UploadedFile::fake()->createWithContent('ptba.xlsx', file_get_contents(PtbaFixtures::sigproProjet(['A2' => ['unit_cost' => 'x']])));

        Volt::test('ptba.import')->set('file', $file)->call('analyze')->call('confirm');

        expect(PtbaImport::first()->status)->toBe(PtbaImport::ANALYSED)
            ->and(Activity::count())->toBe(0);
    });

    test('accès réservé à la coordination et à l\'administrateur', function () {
        $focal = tap(User::factory()->create(['direction_id' => $this->dspa->id]))->assignRole(Role::PointFocal->value);

        $this->actingAs($focal)->get(route('ptba.import'))->assertForbidden();
        $this->actingAs($focal)->get(route('ptba.template', 'projet'))->assertForbidden();
        $this->actingAs($this->coordination)->get(route('ptba.import'))->assertOk();
    });

    test('le modèle téléchargé est relu par l\'import', function () {
        $response = $this->actingAs($this->coordination)->get(route('ptba.template', 'programme'));
        $response->assertOk()->assertDownload('modele-ptba-programme.xlsx');

        expect($this->analyzer->detect($response->baseResponse->getFile()->getPathname()))
            ->toBe(['format' => 'sigpro', 'volet' => 'programme']);
    });
});
