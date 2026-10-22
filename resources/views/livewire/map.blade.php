<?php

use App\Geo\GeoServer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Carte')] class extends Component {
    /** Couches publiées, ou null si GeoServer ne répond pas. */
    #[Computed]
    public function layers(): ?array
    {
        return app(GeoServer::class)->layers();
    }

    public function refresh(): void
    {
        cache()->forget('geoserver.layers');
        unset($this->layers);
    }

    public function config(): array
    {
        return [
            'wmsUrl' => app(GeoServer::class)->publicWmsUrl(),
            'featureInfoUrl' => route('map.feature-info'),
            'layers' => $this->layers ?? [],
            // Côte d'Ivoire.
            'center' => [7.54, -5.55],
            'zoom' => 7,
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Carte</flux:heading>
            <flux:text class="mt-1">Couches publiées par GeoServer (espace de travail « {{ config('sigpro.geoserver.workspace') ?: 'tous' }} »). Cliquez sur la carte pour afficher les informations d'un objet.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button size="sm" icon="arrow-path" wire:click="refresh">Actualiser les couches</flux:button>
            @if (auth()->user()->isAdmin())
                <flux:button size="sm" icon="arrow-top-right-on-square" :href="app(GeoServer::class)->adminUrl()" target="_blank">Ouvrir GeoServer</flux:button>
            @endif
        </div>
    </div>

    @if ($this->layers === null)
        <flux:callout color="amber" icon="exclamation-triangle" heading="GeoServer ne répond pas : seul le fond de carte est affiché." />
    @elseif ($this->layers === [])
        <flux:callout color="blue" icon="information-circle">
            <flux:callout.heading>Aucune couche publiée pour l'instant</flux:callout.heading>
            <flux:callout.text>Les DRENA, localités et autres couches apparaîtront ici dès leur publication dans GeoServer, sans autre réglage.</flux:callout.text>
        </flux:callout>
    @endif

    {{-- wire:ignore : Leaflet gère lui-même le contenu de la carte. --}}
    <div wire:key="map-{{ md5(json_encode($this->layers)) }}" wire:ignore x-data="sigproMap(@js($this->config()))"
        class="relative grid min-h-[70vh] overflow-hidden rounded-xl border border-zinc-200 bg-white lg:grid-cols-[1fr_280px] dark:border-zinc-700 dark:bg-zinc-800">
        <div x-ref="map" class="min-h-[60vh] lg:min-h-full" role="application" aria-label="Carte"></div>

        <aside class="space-y-5 border-t border-zinc-200 p-4 text-sm lg:border-s lg:border-t-0 dark:border-zinc-700">
            <div>
                <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Fond de carte</div>
                <div class="flex gap-1">
                    <button type="button" x-on:click="setBase('plan')" :class="base === 'plan' ? 'bg-prseb-navy-600 text-white' : 'bg-zinc-100 dark:bg-zinc-700'" class="rounded-md px-3 py-1">Plan</button>
                    <button type="button" x-on:click="setBase('clair')" :class="base === 'clair' ? 'bg-prseb-navy-600 text-white' : 'bg-zinc-100 dark:bg-zinc-700'" class="rounded-md px-3 py-1">Clair</button>
                    <button type="button" x-on:click="fullscreen()" class="ms-auto rounded-md bg-zinc-100 px-2 py-1 dark:bg-zinc-700" title="Plein écran" aria-label="Plein écran">⛶</button>
                </div>
            </div>

            <div>
                <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Couches</div>
                <template x-if="! layers.length">
                    <p class="text-zinc-500">Aucune couche disponible.</p>
                </template>
                <ul class="space-y-3">
                    <template x-for="layer in layers" :key="layer.name">
                        <li>
                            <div class="flex items-start gap-2">
                                <input type="checkbox" class="mt-0.5 accent-[var(--color-prseb-navy-600)]" :checked="layer.visible" x-on:change="toggle(layer)" :id="'layer-' + layer.name">
                                <label class="flex-1 cursor-pointer leading-tight" :for="'layer-' + layer.name">
                                    <span class="font-medium" x-text="layer.title"></span>
                                    <span class="block text-xs text-zinc-500" x-text="layer.name"></span>
                                </label>
                                <button type="button" x-show="layer.bbox" x-on:click="zoomTo(layer)" class="text-xs text-prseb-navy-600 hover:underline dark:text-prseb-navy-300">Centrer</button>
                            </div>
                            <input type="range" min="0.1" max="1" step="0.1" x-model.number="layer.opacity" x-on:input="setOpacity(layer)" x-show="layer.visible"
                                class="mt-1 w-full accent-[var(--color-prseb-navy-600)]" :aria-label="'Opacité de ' + layer.title">
                        </li>
                    </template>
                </ul>
            </div>

            <p x-show="loading" class="text-zinc-500">Interrogation de GeoServer…</p>
            <p x-show="error" x-text="error" class="text-red-600"></p>
        </aside>
    </div>
</div>
