<?php

namespace App\Http\Controllers;

use App\Models\TdrDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TdrDocumentController extends Controller
{
    /** Téléchargement, ou aperçu dans le navigateur pour un PDF (?apercu=1). */
    public function __invoke(Request $request, TdrDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document->tdr);

        abort_unless($document->exists(), 404, 'Fichier introuvable.');

        $disposition = $request->boolean('apercu') && $document->isPdf() ? 'inline' : 'attachment';

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
        ], $disposition);
    }
}
