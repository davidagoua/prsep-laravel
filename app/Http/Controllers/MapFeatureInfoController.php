<?php

namespace App\Http\Controllers;

use App\Geo\GeoServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Relais GetFeatureInfo vers GeoServer : le navigateur n'interroge que SIGPRO (pas de CORS),
 * et seules les couches publiées dans l'espace de travail peuvent être interrogées.
 */
class MapFeatureInfoController extends Controller
{
    public function __invoke(Request $request, GeoServer $geoserver): JsonResponse
    {
        $data = $request->validate([
            'layers' => ['required', 'array', 'min:1', 'max:10'],
            'layers.*' => ['string'],
            'bbox' => ['required', 'regex:/^-?[\d.]+,-?[\d.]+,-?[\d.]+,-?[\d.]+$/'],
            'width' => ['required', 'integer', 'between:1,4096'],
            'height' => ['required', 'integer', 'between:1,4096'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
        ]);

        $published = collect($geoserver->layers() ?? [])->pluck('name');
        $layers = array_values(array_filter($data['layers'], fn ($l) => $published->contains($l)));
        abort_if($layers === [], 422, 'Couche inconnue.');

        try {
            $result = $geoserver->featureInfo($layers, $data);
        } catch (Throwable) {
            return response()->json(['message' => 'GeoServer ne répond pas.'], 502);
        }

        // Seules les propriétés sont utiles à la fenêtre d'information.
        return response()->json(['features' => collect($result['features'] ?? [])->map(fn ($f) => [
            'layer' => str($f['id'] ?? '')->before('.')->toString(),
            'properties' => $f['properties'] ?? [],
        ])->values()]);
    }
}
