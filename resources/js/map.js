/**
 * Carte SIGPRO : fonds de carte, couches publiées par GeoServer (WMS) et informations au clic.
 * Leaflet est chargé à la demande, seulement sur la page Carte.
 */
export default (config) => ({
    layers: config.layers.map((layer) => ({ ...layer, visible: false, opacity: 0.8 })),
    base: 'plan',
    loading: false,
    error: null,

    async init() {
        const L = (await import('leaflet')).default;
        await import('leaflet/dist/leaflet.css');
        this.L = L;

        this.map = L.map(this.$refs.map, { zoomControl: true }).setView(config.center, config.zoom);
        L.control.scale({ imperial: false }).addTo(this.map);

        this.bases = {
            plan: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; contributeurs OpenStreetMap',
            }),
            clair: L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap, &copy; CARTO',
            }),
        };
        this.bases[this.base].addTo(this.map);

        this.wms = {};
        this.layers.forEach((layer) => {
            this.wms[layer.name] = L.tileLayer.wms(config.wmsUrl, {
                layers: layer.name, format: 'image/png', transparent: true, version: '1.1.1', opacity: layer.opacity,
            });
        });

        // Les premières couches publiées sont affichées d'emblée.
        this.layers.slice(0, 2).forEach((layer) => this.toggle(layer, true));

        this.map.on('click', (event) => this.identify(event));
        // La carte est dans une grille : recalcul de sa taille une fois affichée.
        setTimeout(() => this.map.invalidateSize(), 50);
    },

    setBase(name) {
        this.map.removeLayer(this.bases[this.base]);
        this.base = name;
        this.bases[name].addTo(this.map).bringToBack();
    },

    toggle(layer, force = null) {
        layer.visible = force ?? ! layer.visible;
        layer.visible ? this.wms[layer.name].addTo(this.map) : this.map.removeLayer(this.wms[layer.name]);
    },

    setOpacity(layer) {
        this.wms[layer.name].setOpacity(layer.opacity);
    },

    zoomTo(layer) {
        if (! layer.bbox) return;
        const [w, s, e, n] = layer.bbox;
        this.map.fitBounds([[s, w], [n, e]]);
        if (! layer.visible) this.toggle(layer, true);
    },

    fullscreen() {
        document.fullscreenElement ? document.exitFullscreen() : this.$root.requestFullscreen();
        setTimeout(() => this.map.invalidateSize(), 200);
    },

    async identify(event) {
        const visible = this.layers.filter((l) => l.visible).map((l) => l.name);
        if (! visible.length) return;

        const size = this.map.getSize();
        const point = this.map.latLngToContainerPoint(event.latlng);
        const params = new URLSearchParams({
            bbox: this.map.getBounds().toBBoxString(),
            width: size.x, height: size.y, x: Math.round(point.x), y: Math.round(point.y),
        });
        visible.forEach((name) => params.append('layers[]', name));

        this.loading = true;
        try {
            const response = await fetch(`${config.featureInfoUrl}?${params}`, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (! response.ok) throw new Error(data.message || 'Erreur');
            if (data.features.length) {
                this.L.popup({ maxWidth: 360, maxHeight: 320 }).setLatLng(event.latlng).setContent(this.popup(data.features)).openOn(this.map);
            }
            this.error = null;
        } catch (e) {
            this.error = 'Impossible d’interroger GeoServer.';
        } finally {
            this.loading = false;
        }
    },

    popup(features) {
        const escape = (value) => String(value ?? '—').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const title = (name) => this.layers.find((l) => l.name.endsWith(':' + name) || l.name === name)?.title ?? name;

        return features.map((f) => {
            const rows = Object.entries(f.properties)
                .filter(([, v]) => v !== null && typeof v !== 'object')
                .map(([k, v]) => `<tr><th style="text-align:left;padding-right:8px;color:#71717a;font-weight:500">${escape(k)}</th><td>${escape(v)}</td></tr>`)
                .join('');
            return `<div style="font-weight:600;margin-bottom:4px">${escape(title(f.layer))}</div><table style="font-size:12px">${rows}</table>`;
        }).join('<hr style="margin:6px 0">');
    },
});
