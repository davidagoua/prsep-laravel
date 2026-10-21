<?php

namespace App\Jobs;

use App\Imports\Ptba\ParsedPtba;
use App\Imports\Ptba\PtbaImporter;
use App\Models\PtbaImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Enregistre un PTBA déjà analysé et validé par l'utilisateur.
 */
class ImportPtba implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public PtbaImport $import) {}

    public function handle(PtbaImporter $importer): void
    {
        $this->import->update(['status' => PtbaImport::RUNNING, 'started_at' => now(), 'progress' => 0]);

        $parsed = ParsedPtba::fromArray(json_decode(Storage::disk('local')->get($this->import->parsedPath()), true));
        $stats = $importer->import($parsed, $this->import->exercice, fn (int $p) => $this->import->update(['progress' => $p]));

        $this->import->update([
            'status' => PtbaImport::DONE,
            'progress' => 100,
            'finished_at' => now(),
            'summary' => array_merge($this->import->summary ?? [], ['result' => $stats]),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->import->update([
            'status' => PtbaImport::FAILED,
            'finished_at' => now(),
            'error' => 'L\'import a échoué et aucune donnée n\'a été modifiée : '.$e->getMessage(),
        ]);
    }
}
