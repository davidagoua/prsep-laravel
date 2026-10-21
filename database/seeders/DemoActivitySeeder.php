<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\Volet;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Exercice;
use App\Models\User;
use App\Workflow\TdrWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

/**
 * Activités et TDR de démonstration (environnement local uniquement),
 * pour essayer le circuit avec les comptes de démo.
 */
class DemoActivitySeeder extends Seeder
{
    private const ACTIVITIES = [
        ['DSPA', Volet::Projet, 'A1.1.01', 'Organiser les ateliers régionaux de formation des enseignants du primaire'],
        ['DSPA', Volet::Projet, 'A1.1.02', 'Produire et diffuser les guides pédagogiques de lecture CP1-CP2'],
        ['DSPA', Volet::Projet, 'A1.2.01', 'Réaliser l\'enquête de suivi des acquis scolaires (ILD 3)'],
        ['DSPA', Volet::Projet, 'A2.1.03', 'Acquérir les kits scolaires pour les écoles des zones prioritaires'],
        ['DSPA', Volet::Programme, 'P3.2.01', 'Tenir les sessions du comité de pilotage sectoriel'],
        ['DSPA', Volet::Programme, 'P3.2.04', 'Élaborer le rapport annuel de performance du secteur'],
        ['DAF', Volet::Projet, 'A4.1.01', 'Réaliser l\'audit financier annuel du projet'],
        ['DAF', Volet::Programme, 'P1.1.02', 'Former les gestionnaires de crédits à la chaîne de dépense'],
    ];

    public function run(TdrWorkflow $workflow): void
    {
        $exercice = Exercice::current();
        $focal = User::where('email', 'point.focal@sigpro.test')->first();
        if (! $exercice || ! $focal || Activity::exists()) {
            return;
        }

        $activities = collect(self::ACTIVITIES)->map(fn (array $row) => Activity::create([
            'direction_id' => Direction::where('code', $row[0])->value('id'),
            'volet' => $row[1],
            'code' => $row[2],
            'label' => $row[3],
            'exercice_id' => $exercice->id,
            'focal_user_id' => $row[0] === 'DSPA' ? $focal->id : null,
            'starts_on' => now()->startOfMonth()->addMonths(rand(0, 2)),
            'ends_on' => now()->addMonths(rand(3, 6))->endOfMonth(),
        ]));

        // Quelques dossiers à différents stades, sans envoyer d'e-mails.
        Notification::fake();
        $directeur = User::role(Role::DirecteurCentral->value)->where('direction_id', $focal->direction_id)->first();

        $enValidation = $workflow->create($activities[0], $focal, 'TDR ateliers régionaux de formation', $this->pdf());
        $workflow->submit($enValidation, $focal);

        $chezCoordination = $workflow->create($activities[1], $focal, 'TDR guides pédagogiques', $this->pdf());
        $workflow->submit($chezCoordination, $focal);
        $workflow->approve($chezCoordination, $directeur, 'Budget conforme au PTBA.');

        $aCorriger = $workflow->create($activities[2], $focal, 'TDR enquête de suivi des acquis', $this->pdf());
        $workflow->submit($aCorriger, $focal);
        $workflow->requestChanges($aCorriger, $directeur, 'Préciser l\'échantillon des écoles et le calendrier de collecte.');

        $workflow->create($activities[4], $focal, 'TDR comité de pilotage', $this->pdf());
    }

    private function pdf(): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            ."4 0 obj<</Length 52>>stream\nBT /F1 18 Tf 72 760 Td (TDR de demonstration) Tj ET\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        return UploadedFile::fake()->createWithContent('tdr-demo.pdf', $content);
    }
}
