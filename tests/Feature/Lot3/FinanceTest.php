<?php

use App\Enums\DrfStatus;
use App\Enums\Role;
use App\Finance\FinanceException;
use App\Finance\FinanceService;
use App\Imports\Ptba\PtbaAnalyzer;
use App\Imports\Ptba\PtbaImporter;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Drf;
use App\Models\Exercice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PtbaFixtures;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->dspa = Direction::factory()->create(['code' => 'DSPA']);
    Direction::factory()->create(['code' => 'DAF']);
    $this->exercice = Exercice::factory()->current()->create(['year' => 2025, 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    app(PtbaImporter::class)->import(app(PtbaAnalyzer::class)->analyze(PtbaFixtures::legacyProjet(), $this->exercice), $this->exercice);

    $this->finance = app(FinanceService::class);
    $this->coordination = tap(User::factory()->create())->assignRole(Role::Coordination->value);
    $this->focal = tap(User::factory()->create(['direction_id' => $this->dspa->id]))->assignRole(Role::PointFocal->value);
    $this->ateliers = Activity::where('label', 'Organiser deux ateliers')->first(); // planifié : 600 000
});

function engage(Activity $a, User $u, int $amount, array $extra = [])
{
    return app(FinanceService::class)->saveEngagement($a, $u, ['amount' => $amount, 'engaged_on' => '2025-03-01'] + $extra);
}

function pay(Activity $a, User $u, int $amount, array $extra = [])
{
    return app(FinanceService::class)->savePayment($a, $u, ['amount' => $amount, 'paid_on' => '2025-03-15'] + $extra);
}

describe('règles de gestion', function () {
    test('on ne paie pas plus que ce qui est engagé', function () {
        engage($this->ateliers, $this->coordination, 400_000);
        pay($this->ateliers, $this->coordination, 300_000);

        expect(fn () => pay($this->ateliers, $this->coordination, 150_000))
            ->toThrow(FinanceException::class, 'reste à payer de l\'activité (100 000 FCFA)');
    });

    test('ni plus que le reste d\'un engagement précis', function () {
        $hotel = engage($this->ateliers, $this->coordination, 200_000, ['label' => 'Hôtel']);
        engage($this->ateliers, $this->coordination, 300_000, ['label' => 'Transport']);

        expect(fn () => pay($this->ateliers, $this->coordination, 250_000, ['engagement_id' => $hotel->id]))
            ->toThrow(FinanceException::class, 'cet engagement');
    });

    test('un engagement payé ne se supprime pas et ne descend pas sous ses paiements', function () {
        $e = engage($this->ateliers, $this->coordination, 400_000);
        pay($this->ateliers, $this->coordination, 300_000, ['engagement_id' => $e->id]);

        expect(fn () => $this->finance->deleteEngagement($e, $this->coordination))->toThrow(FinanceException::class);
        expect(fn () => $this->finance->saveEngagement($this->ateliers, $this->coordination, ['amount' => 200_000, 'engaged_on' => '2025-03-01'], $e))
            ->toThrow(FinanceException::class, 'inférieur');
    });

    test('seule la coordination saisit', function () {
        expect(fn () => engage($this->ateliers, $this->focal, 100_000))->toThrow(AuthorizationException::class);
    });

    test('cycle d\'une DRF : brouillon, soumise (paiements figés), remboursée', function () {
        engage($this->ateliers, $this->coordination, 500_000);
        $p1 = pay($this->ateliers, $this->coordination, 200_000);
        $p2 = pay($this->ateliers, $this->coordination, 100_000);

        $drf = $this->finance->createDrf($this->exercice, $this->coordination, Drf::nextNumber($this->exercice), '2025-04-01', null);
        expect($drf->number)->toBe('DRF-2025-01');

        expect(fn () => $this->finance->submitDrf($drf, $this->coordination))->toThrow(FinanceException::class, 'au moins un paiement');

        expect($this->finance->attachPayments($drf, $this->coordination, [$p1->id, $p2->id]))->toBe(2);
        $this->finance->submitDrf($drf->fresh(), $this->coordination);

        expect($p1->fresh()->isLocked())->toBeTrue();
        expect(fn () => $this->finance->deletePayment($p1->fresh(), $this->coordination))->toThrow(FinanceException::class);
        expect(fn () => $this->finance->detachPayment($drf->fresh(), $this->coordination, $p1))->toThrow(FinanceException::class);

        $this->finance->reimburseDrf($drf->fresh(), $this->coordination, '2025-05-10');
        expect($drf->fresh())->status->toBe(DrfStatus::Remboursee)->reimbursed_on->toDateString()->toBe('2025-05-10');
    });

    test('une DRF rejetée redevient modifiable', function () {
        engage($this->ateliers, $this->coordination, 500_000);
        $p = pay($this->ateliers, $this->coordination, 200_000);
        $drf = $this->finance->createDrf($this->exercice, $this->coordination, 'DRF-A', '2025-04-01', null);
        $this->finance->attachPayments($drf, $this->coordination, [$p->id]);
        $this->finance->submitDrf($drf->fresh(), $this->coordination);

        $this->finance->rejectDrf($drf->fresh(), $this->coordination, 'Pièces justificatives manquantes');

        expect($drf->fresh()->isEditable())->toBeTrue()
            ->and($p->fresh()->isLocked())->toBeFalse();
        $this->finance->detachPayment($drf->fresh(), $this->coordination, $p);
        expect($p->fresh()->drf_id)->toBeNull();
    });

    test('le numéro de DRF est unique par exercice', function () {
        $this->finance->createDrf($this->exercice, $this->coordination, 'DRF-1', '2025-04-01', null);

        expect(fn () => $this->finance->createDrf($this->exercice, $this->coordination, 'DRF-1', '2025-04-02', null))->toThrow(FinanceException::class);
    });
});

describe('écrans', function () {
    test('le tableau financier cumule planifié, engagé et payé', function () {
        engage($this->ateliers, $this->coordination, 500_000);
        pay($this->ateliers, $this->coordination, 300_000);

        $this->actingAs($this->coordination)
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee('2 600 000') // planifié total
            ->assertSee('500 000')   // engagé
            ->assertSee('300 000')   // payé
            ->assertSee('50 %');     // 300 000 / 600 000 pour l'activité
    });

    test('le point focal n\'accède pas au suivi financier global mais voit celui de son activité', function () {
        engage($this->ateliers, $this->coordination, 500_000);

        $this->actingAs($this->focal)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($this->focal)->get(route('activities.show', $this->ateliers))
            ->assertSee('Exécution financière')
            ->assertSee('500 000')
            ->assertDontSee('wire:click="newEngagement"', false);
    });

    test('saisie depuis le panneau de l\'activité', function () {
        $this->actingAs($this->coordination);

        Volt::test('finance.activity', ['activityId' => $this->ateliers->id])
            ->call('newEngagement')
            ->set('e_amount', 450_000)
            ->set('e_label', 'Hôtellerie')
            ->call('saveEngagement')
            ->assertHasNoErrors()
            ->assertDispatched('finance-updated')
            ->call('newPayment')
            ->assertSet('p_engagement', (string) $this->ateliers->engagements()->first()->id)
            ->set('p_amount', 500_000)
            ->call('savePayment')
            ->assertHasErrors('p_amount')
            ->set('p_amount', 450_000)
            ->call('savePayment')
            ->assertHasNoErrors();

        expect(Payment::sum('amount'))->toEqual(450_000);
    });

    test('la page DRF regroupe les paiements et suit le statut', function () {
        engage($this->ateliers, $this->coordination, 500_000);
        $p = pay($this->ateliers, $this->coordination, 200_000, ['reference' => 'MANDAT-12']);
        $this->actingAs($this->coordination);

        $page = Volt::test('finance.drf')
            ->assertSee('1 paiement(s)')
            ->call('newDrf')
            ->assertSet('number', 'DRF-2025-01')
            ->call('create');

        $drf = Drf::first();
        $page->assertSet('selectedId', $drf->id)
            ->set('toAttach', [(string) $p->id])
            ->call('attach')
            ->assertSee('MANDAT-12')
            ->call('submit');

        expect($drf->fresh()->status)->toBe(DrfStatus::Soumise);
    });

    test('exports Excel', function () {
        engage($this->ateliers, $this->coordination, 500_000);
        $p = pay($this->ateliers, $this->coordination, 200_000);
        $drf = $this->finance->createDrf($this->exercice, $this->coordination, 'DRF-X', '2025-04-01', null);
        $this->finance->attachPayments($drf, $this->coordination, [$p->id]);
        $this->actingAs($this->coordination);

        $tree = $this->get(route('finance.export', ['volet' => 'projet']));
        $tree->assertOk()->assertDownload('suivi-financier-projet-2025.xlsx');
        $sheet = IOFactory::load($tree->baseResponse->getFile()->getPathname())->getActiveSheet();
        expect($sheet->getCell('C4')->getValue())->toContain('Assistance Technique')
            ->and(collect($sheet->toArray(null, true, false))->last()[4])->toEqual(2_600_000);

        $this->get(route('finance.drf.export', $drf))->assertOk()->assertDownload('DRF-X.xlsx');
    });
});
