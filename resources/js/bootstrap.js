import axios from 'axios';
import { registerMapStore } from './map-store';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// The Leaflet lifecycle store (issue #028) is registered on `alpine:init`, not
// at module evaluation time: window.Alpine does not exist yet when this module
// runs, and the event always fires before the first x-data is initialised.
// Listening to the event also keeps this correct if @livewireScripts ever moves
// into <head>.
//
// The import must be STATIC. A dynamic import() here resolves in a microtask,
// which lands AFTER Livewire's start() has already dispatched alpine:init — the
// store would then register too late and every x-data init() would find no
// store, leaving maps silently blank.
document.addEventListener('alpine:init', () => {
  registerMapStore(window.Alpine);
});
