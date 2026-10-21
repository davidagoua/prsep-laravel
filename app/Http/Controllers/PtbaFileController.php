<?php

namespace App\Http\Controllers;

use App\Imports\Ptba\TemplateBuilder;
use App\Models\PtbaImport;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PtbaFileController extends Controller
{
    /** Modèle Excel SIGPRO d'un volet, généré avec les référentiels à jour. */
    public function template(string $volet, TemplateBuilder $builder): BinaryFileResponse
    {
        Gate::authorize('import-ptba');
        abort_unless(in_array($volet, ['projet', 'programme'], true), 404);

        $path = tempnam(sys_get_temp_dir(), 'ptba').'.xlsx';
        $builder->build($volet, $path);

        return response()->download($path, "modele-ptba-{$volet}.xlsx")->deleteFileAfterSend();
    }

    /** Rapport des anomalies d'une analyse, à corriger dans le fichier source. */
    public function report(PtbaImport $import): StreamedResponse
    {
        Gate::authorize('import-ptba');

        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Niveau', 'Feuille', 'Ligne', 'Message'], ';');
            foreach ($import->issues ?? [] as $issue) {
                fputcsv($out, [$issue['level'] === 'error' ? 'Erreur' : 'Avertissement', $issue['sheet'], $issue['row'], $issue['message']], ';');
            }
            fclose($out);
        }, "anomalies-import-{$import->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
