<?php

namespace App\Http\Controllers;

use App\Collabora\Discovery;
use App\Collabora\WopiFile;
use App\Collabora\WopiToken;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentEditorController extends Controller
{
    /** Ouvre un document dans Collabora (édition si l'utilisateur peut modifier, lecture sinon). */
    public function __invoke(Request $request, string $fileId, Discovery $discovery): View
    {
        $file = WopiFile::find($fileId);
        abort_unless($file, 404);
        abort_unless($file->canView($request->user()), 403);

        $write = $file->canWrite($request->user());
        $action = $discovery->actionUrl($file->extension(), $write);
        $token = WopiToken::issue($fileId, $request->user()->id, $write);
        $wopiSrc = rtrim(config('sigpro.collabora.wopi_host'), '/').'/wopi/files/'.$fileId;

        return view('documents.editor', [
            'file' => $file,
            'write' => $write,
            'action' => $action ? $action.'WOPISrc='.urlencode($wopiSrc).'&lang=fr' : null,
            'token' => $token,
        ]);
    }
}
