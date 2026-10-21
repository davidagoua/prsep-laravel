<?php

namespace App\Http\Controllers;

use App\Models\ReportDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportDocumentController extends Controller
{
    public function __invoke(ReportDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document->report);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404, 'Fichier introuvable.');

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
