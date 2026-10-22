<?php

namespace App\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Accès à GeoServer : couches publiées (WMS GetCapabilities) et interrogation des objets
 * sous un point de la carte (GetFeatureInfo). Les couches sont limitées à l'espace de travail configuré.
 */
class GeoServer
{
    /** URL WMS publique utilisée par le navigateur pour les tuiles. */
    public function publicWmsUrl(): string
    {
        return $this->wmsUrl(config('sigpro.geoserver.url'));
    }

    /**
     * Couches publiées, ou null si GeoServer est injoignable.
     *
     * @return list<array{name: string, title: string, abstract: ?string, bbox: ?array{0: float, 1: float, 2: float, 3: float}}>|null
     */
    public function layers(): ?array
    {
        return Cache::remember('geoserver.layers', now()->addMinutes(5), function () {
            try {
                $xml = Http::timeout(5)->get($this->wmsUrl(config('sigpro.geoserver.internal_url')), [
                    'service' => 'WMS', 'version' => '1.3.0', 'request' => 'GetCapabilities',
                ])->throw()->body();
            } catch (Throwable) {
                return null;
            }

            $doc = @simplexml_load_string($xml);
            if (! $doc) {
                return null;
            }
            $doc->registerXPathNamespace('w', 'http://www.opengis.net/wms');

            $layers = [];
            foreach ($doc->xpath('//w:Layer[w:Name]') as $layer) {
                $box = $layer->EX_GeographicBoundingBox;
                $layers[] = [
                    'name' => (string) $layer->Name,
                    'title' => (string) ($layer->Title ?: $layer->Name),
                    'abstract' => trim((string) $layer->Abstract) ?: null,
                    'bbox' => $box ? [(float) $box->westBoundLongitude, (float) $box->southBoundLatitude, (float) $box->eastBoundLongitude, (float) $box->northBoundLatitude] : null,
                ];
            }

            return $layers;
        });
    }

    /**
     * Objets sous un point de la carte, en GeoJSON.
     *
     * @param  list<string>  $layers  noms de couches déjà validés
     * @param  array{bbox: string, width: int, height: int, x: int, y: int}  $view
     */
    public function featureInfo(array $layers, array $view): array
    {
        $response = Http::timeout(8)->get($this->wmsUrl(config('sigpro.geoserver.internal_url')), [
            'service' => 'WMS', 'version' => '1.1.1', 'request' => 'GetFeatureInfo',
            'layers' => implode(',', $layers), 'query_layers' => implode(',', $layers),
            'srs' => 'EPSG:4326', 'bbox' => $view['bbox'], 'width' => $view['width'], 'height' => $view['height'],
            'x' => $view['x'], 'y' => $view['y'], 'info_format' => 'application/json', 'feature_count' => 10,
        ])->throw();

        return $response->json() ?? ['type' => 'FeatureCollection', 'features' => []];
    }

    public function adminUrl(): string
    {
        return rtrim(config('sigpro.geoserver.url'), '/').'/web/';
    }

    private function wmsUrl(string $base): string
    {
        $workspace = config('sigpro.geoserver.workspace');

        return rtrim($base, '/').($workspace ? '/'.$workspace : '').'/wms';
    }
}
