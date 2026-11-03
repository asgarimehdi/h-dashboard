/**
 * Host-page map logic (#1352 CSP-compat).
 *
 * WHY THIS EXISTS
 * ---------------
 * Every host page used to carry its map behavior in a `@script` block.
 * Under the CSP-safe build, Livewire evaluates `@script` bodies as
 * IIFE-wrapped expressions through the CSP parser — and the parser
 * cannot even parse the IIFE wrapper itself (measured: a trivial body
 * `window.__trivialScript = 'ran';` fails with `Unexpected token:
 * PUNCTUATION ")"`). NO `@script` body can execute, whatever its
 * content. Host behavior therefore lives here, in plain JS.
 *
 * Each host page publishes its server data in an inert JSON block
 * (`<script type="application/json" id="...-data">`), which never
 * executes and needs no CSP exception. This module wires the behavior
 * when the block is present on the page, on both `livewire:init`
 * (first load) and `livewire:navigated` (SPA navigation); setup is
 * keyed on the block element so a double event for the same page is
 * a no-op, and navigating away cleans the globals up.
 *
 * Directive expressions cannot call globals either (measured:
 * `@click="window.__g(7)"` and `@click="__g(8)"` both never run), so
 * host interactions are bound here with plain listeners and data
 * attributes — never `x-on:click="someGlobal(...)"`.
 */

let countySetupEl = null;

function setupCountyMap() {
  const block = document.getElementById('county-regions-data');

  if (!block) {
    if (countySetupEl) {
      delete window.toggleGeoJson;
      countySetupEl = null;
    }
    return;
  }
  if (countySetupEl === block) return;

  const store = window.Alpine?.store?.('map');
  if (!store) {
    // The store registers on alpine:init (bootstrap.js). Setup is
    // re-run from that handler and from the Livewire navigation
    // events, so a too-early call is a silent no-op, never a throw —
    // and the block must NOT be marked set up yet, or the retry
    // would skip it.
    return;
  }
  countySetupEl = block;

  let regions = [];
  try {
    regions = JSON.parse(block.textContent || '[]');
  } catch (e) {
    console.error('[map] county regions data is not valid JSON', e);
    return;
  }

  const geojsonLayers = {};
  let map = null;

  // Issue #028 — attach to the live map the store owns. onReady()
  // removes any script-ordering race with maps.map: the callback runs
  // immediately when a map already exists, otherwise once created.
  // toggleGeoJson itself is defined NOW (not inside the callback) so a
  // click before the map is ready warns instead of throwing.
  store.onReady((live) => {
    map = live;
  });

  window.toggleGeoJson = function (regionId) {
    if (!map) {
      console.warn('[map] no live map yet for region:', regionId);
      return;
    }

    const region = regions.find((r) => r.id === regionId);
    if (!region || !region.geojson) {
      console.warn('No geojson found for region:', regionId);
      return;
    }

    if (geojsonLayers[regionId]) {
      map.removeLayer(geojsonLayers[regionId]);
      delete geojsonLayers[regionId];
    } else {
      try {
        const data = typeof region.geojson === 'string'
          ? JSON.parse(region.geojson)
          : region.geojson;

        const newLayer = L.geoJSON(data, {
          style: {
            color: 'orange',
            weight: 2,
            opacity: 0.8,
            fillOpacity: 0.1,
          },
        }).addTo(map);

        geojsonLayers[regionId] = newLayer;

        // Zoom to layer bounds
        map.fitBounds(newLayer.getBounds());
      } catch (e) {
        console.error('Error parsing GeoJSON:', e);
      }
    }
  };

  // The toggles are MaryUI components whose root element does not
  // forward a data attribute, so each toggle is wrapped in a
  // [data-region-id] div in the Blade template and toggles delegate
  // from the menu. (The old x-on:click="toggleGeoJson(...)" binding
  // cannot run under the CSP build — see the header note.)
  //
  // The handler listens for `change`, not `click`: clicking a <label>
  // fires a click on the label AND a synthesized click on its input,
  // so a click listener would run toggleGeoJson twice (add then
  // remove) and net zero. `change` fires exactly once per user
  // toggle, including keyboard toggling.
  const menu = document.getElementById('unitMenu');
  if (menu) {
    menu.addEventListener('change', (event) => {
      const item = event.target.closest?.('[data-region-id]');
      if (!item || !menu.contains(item)) return;
      const regionId = Number(item.dataset.regionId);
      if (window.toggleGeoJson) window.toggleGeoJson(regionId);
    });
  }
}

let routeSetupEl = null;

function setupRouteMap() {
  const block = document.getElementById('route-data');
  if (!block) return;
  if (routeSetupEl === block) return;
  const store = window.Alpine?.store?.('map');
  if (!store) return;
  routeSetupEl = block;

  let config = {};
  try {
    config = JSON.parse(block.textContent || '{}');
  } catch (e) {
    console.error('[map] route data is not valid JSON', e);
    return;
  }

  const parseWaypoint = (value) => {
    const parts = String(value || '').split(',').map(Number);
    if (parts.length !== 2 || parts.some((n) => !isFinite(n))) return null;
    return L.latLng(parts[0], parts[1]);
  };

  let routingControl = null;

  const toggleRoutingContainer = () => {
    if (!routingControl || !routingControl._container) return;
    const container = routingControl._container;
    container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
  };

  // The toggle is wrapped in [data-route-toggle] (see the Blade
  // template); `change` fires exactly once per user toggle, where a
  // click listener would double-fire through the label/input pair.
  document.querySelector('[data-route-toggle]')?.addEventListener('change', toggleRoutingContainer);

  // Issue #028 — bind to the live map the store owns. onReady()
  // replaces the old `if (window.map)` guard and removes the
  // script-ordering race with maps.map.
  store.onReady((map) => {
    routingControl = L.Routing.control({
      waypoints: [parseWaypoint(config.waypoint1), parseWaypoint(config.waypoint2)].filter(Boolean),
      router: L.Routing.osrmv1({
        serviceUrl: `${config.routingUrl}/route/v1`,
      }),
      lineOptions: {
        styles: [{ color: 'blue', weight: 5 }],
      },
      routeWhileDragging: true,
      show: true,
    }).addTo(map);

    routingControl.on('routesfound', (e) => {
      const route = e.routes[0];
      document.getElementById('distance').textContent = (route.summary.totalDistance / 1000).toFixed(2);
      document.getElementById('duration').textContent = Math.ceil(route.summary.totalTime / 60);
    });

    setTimeout(() => {
      if (routingControl._container) {
        routingControl._container.style.display = 'none';
      }
    }, 200);
  });
}

let route2SetupEl = null;

function setupRoute2Map() {
  const block = document.getElementById('route2-data');
  if (!block) return;
  if (route2SetupEl === block) return;
  const store = window.Alpine?.store?.('map');
  if (!store) return;
  route2SetupEl = block;

  let config = {};
  try {
    config = JSON.parse(block.textContent || '{}');
  } catch (e) {
    console.error('[map] route2 data is not valid JSON', e);
    return;
  }

  const parseCoordinates = (input) => {
    if (!input) return null;
    const coords = input.split(',').map(Number);
    if (coords.length === 2 && !isNaN(coords[0]) && !isNaN(coords[1])) {
      return L.latLng(coords[0], coords[1]);
    }
    return null;
  };

  /**
   * Build the Nominatim search URL with the query encoded by URLSearchParams.
   *
   * Hand-written `'?q=' + query` is how a library swap breaks silently: an
   * address containing `&` or `#` would truncate the query, axios used to
   * encode it for us.
   */
  const geocodeSearchUrl = (base, query) => {
    const url = new URL(`${base}/search`);
    url.searchParams.set('q', query);
    url.searchParams.set('format', 'json');
    url.searchParams.set('limit', '1');
    return url.toString();
  };

  /**
   * Nominatim answers with an array of `{lat, lon}`. An error body (object or
   * string) is not an array, and `[].length > 0` on a non-array is
   * `undefined > 0` — false, but only by accident. Be explicit.
   */
  const parseGeocodeResponse = (data) => {
    if (!Array.isArray(data) || data.length === 0) {
      return null;
    }
    const result = data[0];
    if (result === null || typeof result !== 'object') {
      return null;
    }
    const lat = Number(result.lat);
    const lon = Number(result.lon);
    if (!isFinite(lat) || !isFinite(lon)) {
      return null;
    }
    return L.latLng(lat, lon);
  };

  const geocode = async (query) => {
    if (!query) return null;
    try {
      const response = await fetch(geocodeSearchUrl(config.geocodingUrl, query), {
        headers: { Accept: 'application/json' },
      });
      // fetch, unlike axios, resolves on 4xx/5xx — check it ourselves.
      if (!response.ok) {
        console.error('Geocoding error:', response.status, response.statusText);
        return null;
      }
      return parseGeocodeResponse(await response.json());
    } catch (error) {
      console.error('Geocoding error:', error);
    }
    return null;
  };

  let routingControl = null;
  let map = null;

  store.onReady((live) => {
    map = live;

    if (typeof L.Routing === 'undefined' || typeof L.Routing.control !== 'function') {
      console.error('L.Routing not available. Make sure leaflet-routing-machine.js is loaded.');
      return;
    }

    routingControl = L.Routing.control({
      waypoints: [],
      router: L.Routing.osrmv1({
        serviceUrl: `${config.routingUrl}/route/v1`,
      }),
      routeWhileDragging: true,
      show: true,
    }).addTo(map);

    routingControl.on('routesfound', (e) => {
      const summary = e.routes[0].summary;
      document.getElementById('distance').textContent = (summary.totalDistance / 1000).toFixed(2);
      document.getElementById('duration').textContent = Math.ceil(summary.totalTime / 60);
    });

    routingControl.on('routingerror', (e) => {
      console.error('Routing error:', e.error);
    });

    setTimeout(() => {
      if (routingControl._container) {
        routingControl._container.style.display = 'none';
      }
    }, 200);
  });

  // Issue #028 — these used to be bound to `window` because the Blade
  // x-on:click handlers called them as window.searchRoute() etc. Those
  // calls cannot run under the CSP build, so the toolbar buttons are
  // wrapped in [data-action] spans and clicks delegate from the
  // toolbar below. The handlers close over the live `map` handed to us
  // by onReady(), so they can never act on a map from a previous page.
  const searchRoute = async () => {
    if (!routingControl || !map) {
      console.error('Routing control not initialized');
      return;
    }
    const startInput = document.getElementById('start-input');
    const endInput = document.getElementById('end-input');
    const startPoint = parseCoordinates(startInput.value) || await geocode(startInput.value);
    const endPoint = parseCoordinates(endInput.value) || await geocode(endInput.value);
    if (startPoint && endPoint) {
      routingControl.setWaypoints([startPoint, endPoint]);
      map.fitBounds([startPoint, endPoint]);
    } else {
      alert('لطفاً مبدا و مقصد معتبر وارد کنید');
    }
  };

  const reverseRoute = () => {
    if (!routingControl) return;
    const currentWaypoints = routingControl.getWaypoints().slice().reverse();
    const startInput = document.getElementById('start-input');
    const endInput = document.getElementById('end-input');
    const temp = startInput.value;
    startInput.value = endInput.value;
    endInput.value = temp;
    routingControl.setWaypoints(currentWaypoints);
  };

  const toggleRoutingContainer = () => {
    if (!routingControl || !routingControl._container) return;
    const container = routingControl._container;
    container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
  };

  const toolbar = document.getElementById('route2-toolbar');
  if (toolbar) {
    toolbar.addEventListener('click', (event) => {
      const actionEl = event.target.closest?.('[data-action]');
      if (!actionEl || !toolbar.contains(actionEl)) return;
      if (actionEl.dataset.action === 'search-route') void searchRoute();
      else if (actionEl.dataset.action === 'reverse-route') reverseRoute();
    });
    toolbar.addEventListener('change', (event) => {
      const toggleEl = event.target.closest?.('[data-action="toggle-routing"]');
      if (!toggleEl || !toolbar.contains(toggleEl)) return;
      toggleRoutingContainer();
    });
  }
}

let noBoundarySetupEl = null;

function setupMapNoBoundary() {
  const block = document.getElementById('no-boundary-data');
  if (!block) return;
  if (noBoundarySetupEl === block) return;
  const store = window.Alpine?.store?.('map');
  if (!store) return;
  noBoundarySetupEl = block;

  let units = [];
  try {
    units = JSON.parse(block.textContent || '[]');
  } catch (e) {
    console.error('[map] no-boundary data is not valid JSON', e);
    return;
  }

  // Issue #028 — bind to the live map the store owns instead of waiting for a
  // `window.map` a previous page may still own.
  store.onReady((map) => {
    const markers = {};

    units.forEach((unit) => {
      if (unit.has_coords && unit.lat && unit.lng) {
        const marker = L.circleMarker([unit.lat, unit.lng], {
          radius: 8,
          fillColor: '#ef4444',
          color: '#fff',
          weight: 2,
          fillOpacity: 0.9,
        }).addTo(map);

        marker.bindPopup(`<strong>${unit.name}</strong><br>${unit.type}<br>${unit.region}`);

        if (!markers[unit.id]) {
          markers[unit.id] = [];
        }
        markers[unit.id].push(marker);
      }
    });

    // Fit map to markers
    const allLatLngs = Object.values(markers).flat().map((m) => m.getLatLng());
    if (allLatLngs.length > 0) {
      map.fitBounds(allLatLngs, { padding: [30, 30] });
    }
  });
}

export function setupMapPages() {
  if (!window.Alpine || !window.Alpine.store) return;
  setupCountyMap();
  setupRouteMap();
  setupRoute2Map();
  setupMapNoBoundary();
}

// First load: bootstrap.js calls setupMapPages() from its alpine:init
// handler, after the store is registered. SPA navigation re-runs setup
// through the Livewire events below (alpine:init does not re-fire).
document.addEventListener('livewire:init', setupMapPages);
document.addEventListener('livewire:navigated', setupMapPages);
