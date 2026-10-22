/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

// Composant Alpine de la carte (Leaflet chargé à la demande).
import sigproMap from './map';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('sigproMap', sigproMap);
});
