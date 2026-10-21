<?php

namespace App\Http\Controllers;

use App\Collabora\WopiFile;
use App\Collabora\WopiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hôte WOPI appelé par Collabora (CheckFileInfo, GetFile, PutFile).
 * Chaque appel porte le jeton signé émis à l'ouverture de l'éditeur.
 */
class WopiController extends Controller
{
    public function info(Request $request, string $fileId): JsonResponse
    {
        [$file, $user, $token] = $this->authorizeRequest($request, $fileId);

        return response()->json([
            'BaseFileName' => $file->name(),
            'Size' => $file->size(),
            'Version' => $file->version(),
            'OwnerId' => 'sigpro',
            'UserId' => (string) $user->id,
            'UserFriendlyName' => $user->name,
            'UserCanWrite' => $token['w'] && $file->canWrite($user),
            'UserCanNotWriteRelative' => true,
            'SupportsUpdate' => true,
            'SupportsLocks' => false,
            'DisablePrint' => false,
            'HidePrintOption' => false,
            'PostMessageOrigin' => rtrim(config('app.url'), '/'),
        ]);
    }

    public function contents(Request $request, string $fileId): Response
    {
        [$file] = $this->authorizeRequest($request, $fileId);

        return response($file->contents(), 200, [
            'Content-Type' => 'application/octet-stream',
            'X-WOPI-ItemVersion' => $file->version(),
        ]);
    }

    public function save(Request $request, string $fileId): JsonResponse
    {
        [$file, $user, $token] = $this->authorizeRequest($request, $fileId);

        abort_unless($token['w'] && $file->canWrite($user), 401);

        $file->write($user, $request->getContent());

        return response()->json(['LastModifiedTime' => now()->toIso8601String()], 200, [
            'X-WOPI-ItemVersion' => WopiFile::find($fileId)->version(),
        ]);
    }

    /** Opérations de verrouillage : non utilisées (SupportsLocks = false), acceptées sans effet. */
    public function operation(Request $request, string $fileId): Response
    {
        $this->authorizeRequest($request, $fileId);

        return response('', 200);
    }

    /** @return array{0: WopiFile, 1: User, 2: array} */
    private function authorizeRequest(Request $request, string $fileId): array
    {
        $token = WopiToken::verify($request->query('access_token'), $fileId);
        $file = $token ? WopiFile::find($fileId) : null;
        $user = $token ? User::active()->find($token['u']) : null;

        abort_unless($file && $user && $file->canView($user), 401);

        return [$file, $user, $token];
    }
}
