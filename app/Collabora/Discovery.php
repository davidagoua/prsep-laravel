<?php

namespace App\Collabora;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lit la « discovery » de Collabora pour trouver l'URL de l'éditeur selon l'extension du fichier.
 * L'application l'interroge par l'URL interne (réseau Docker) ; le navigateur reçoit l'URL publique.
 */
class Discovery
{
    /** URL d'ouverture (sans paramètres WOPI) ou null si Collabora est injoignable ou le format non pris en charge. */
    public function actionUrl(string $extension, bool $edit): ?string
    {
        $actions = $this->actions();
        $url = $actions[$extension][$edit ? 'edit' : 'view'] ?? $actions[$extension]['view'] ?? $actions[$extension]['edit'] ?? null;
        if (! $url) {
            return null;
        }

        // L'URL renvoyée pointe vers l'hôte interrogé (interne) : on la réécrit vers l'adresse publique.
        $path = preg_replace('#^https?://[^/]+#', '', strtok($url, '?'));

        return rtrim(config('sigpro.collabora.url'), '/').$path.'?';
    }

    public function supports(string $extension): bool
    {
        return isset($this->actions()[$extension]);
    }

    /** @return array<string, array<string, string>> extension => [action => urlsrc] */
    private function actions(): array
    {
        return Cache::remember('collabora.discovery', now()->addHour(), function () {
            try {
                $xml = Http::timeout(5)->get(rtrim(config('sigpro.collabora.internal_url'), '/').'/hosting/discovery')->throw()->body();
            } catch (Throwable) {
                return [];
            }

            $actions = [];
            foreach (simplexml_load_string($xml)->xpath('//action') as $action) {
                $ext = (string) $action['ext'];
                if ($ext !== '') {
                    $actions[$ext][(string) $action['name']] ??= (string) $action['urlsrc'];
                }
            }

            return $actions;
        });
    }
}
