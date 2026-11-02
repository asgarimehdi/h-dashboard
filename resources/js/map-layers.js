/**
 * Remaining map host pages moved out of `@script` blocks (#1352,
 * Step 3 / R3): point markers, unit boundaries, and the unit
 * boundary editor.
 *
 * WHY THIS EXISTS
 * ---------------
 * No `@script` body can execute under the CSP-safe Alpine build (the
 * CSP expression parser cannot parse Livewire's IIFE wrapper). The
 * three bodies below are plain JS that only ever ran because
 * `@script` evaluated them outside the parser; they move here with
 * mechanical translations:
 *
 * - `maps/point`: `$wire.get('location')` becomes
 *   `Livewire.find()` resolved from the `[data-point-map]` marker
 *   on the component root. The checkbox bindings use a dataset
 *   guard (the chunk-5 `jdpBound` pattern) so re-running setup on
 *   the same DOM never stacks duplicate listeners. The canvas
 *   `lineRenderer` is reset per `onReady` (the original got a
 *   fresh one per `@script` evaluation); the icon cache stays
 *   module-level (icons are map-independent).
 * - `maps/unit`: the four layer-state dicts reset on every
 *   `onReady` (the original got fresh state per `@script`
 *   evaluation; stale entries would make `showUnits` skip units
 *   on the new map). The two `Livewire.on` listeners register
 *   once and act on the current map via a module variable. The
 *   `x-on:change` toggle calls were global-function calls in a
 *   directive — dead under the CSP build (Step-1 global-call
 *   probe) — so the template wraps each toggle in
 *   `[data-unit-id]` and this module delegates `change` events
 *   from the document. `window.toggleGeoJsonOn/Off` are still
 *   assigned (the page's observable surface, unchanged).
 * - `units/map`: the body is verbatim, including its direct
 *   `L.map('unitMap')` call. That call pre-dates (and violates)
 *   the AGENTS.md rule reserving `L.map()` to `maps/map.blade.php`;
 *   moving the editor onto the shared store would change the
 *   #702 draw-toolbar flow, so the migration preserves the
 *   local instance and only changes its delivery. Server data
 *   (`tile_url_template`, `$geojson`) comes from one inert JSON
 *   block; `$wire.saveBoundary/deleteBoundary` become
 *   `component.call(...)`; `$wire.on('boundaryUpdated')` becomes
 *   a once-guarded global `Livewire.on` (no other file references
 *   the event). Re-running setup never re-initialises an
 *   initialised container (`_leaflet_id` guard — Leaflet throws
 *   on a second `L.map` for the same element).
 *
 * Entry points mirror map-pages.js: bootstrap calls
 * `setupMapLayers()` from its `alpine:init` handler, and this
 * module re-runs setup on `livewire:init` / `livewire:navigated`.
 */

let unitMapCurrent = null;
let unitListenersRegistered = false;
let unitDelegationRegistered = false;
let boundaryListenerRegistered = false;

/* ---------------- maps/point ---------------- */

const typeIcons = {
  4: '/icons/network.svg',
  5: '/icons/urban-health.svg',
  6: '/icons/urban-rural.svg',
  7: '/icons/rural-health.svg',
  8: '/icons/attached-base.svg',
  9: '/icons/health-house.svg',
  10: '/icons/base.svg',
  11: '/icons/block.svg',
  12: '/icons/satellite.svg',
  13: '/icons/rabies.svg',
  14: '/icons/dental.svg',
  15: '/icons/lab.svg',
  16: '/icons/school.svg',
  17: '/icons/emergency.svg',
  18: '/icons/worker-house.svg',
  19: '/icons/hospital.svg',
};

const defaultIcon = '/icons/default.svg';

// Perf: one L.icon per type (was: a fresh L.icon for every marker).
const iconCache = new Map();
function getIcon(typeId) {
  let icon = iconCache.get(typeId);
  if (!icon) {
    icon = L.icon({
      iconUrl: typeIcons[typeId] ?? defaultIcon,
      iconSize: [32, 32],
      iconAnchor: [16, 32],
      popupAnchor: [0, -32],
    });
    iconCache.set(typeId, icon);
  }
  return icon;
}

const lineColors = ['#14b8a6', '#3b82f6', '#f97316', '#a855f7', '#ef4444'];

function setupPointMap() {
  const marker = document.querySelector('[data-point-map]');
  if (!marker || typeof window.Alpine === 'undefined') {
    return;
  }
  const root = marker.closest('[wire\\:id]');
  const component = root && typeof window.Livewire !== 'undefined'
    ? window.Livewire.find(root.getAttribute('wire:id'))
    : null;
  if (!component) {
    return;
  }

  // The store is registered on `alpine:init`; `livewire:init` can fire
  // first (map-pages.js guard, same shape) — the alpine:init setup
  // call covers first load, `livewire:navigated` covers later ones.
  const store = window.Alpine?.store?.('map');
  if (!store) {
    return;
  }

  // Issue #028 — take a LIVE map from the store instead of waiting for a
  // `window.map` that a previous page may still own. onReady() removes the
  // script-ordering race with maps.map entirely.
  store.onReady(function (map) {
    const markersLayer = L.layerGroup().addTo(map);
    const linesLayer = L.layerGroup().addTo(map);

    // Attach the shared canvas renderer up front: it registers its
    // zoomanim listener once, here, instead of whenever the first line
    // happens to be drawn. Fresh per map, like the original.
    let lineRenderer = L.canvas({ padding: 0.5 });
    lineRenderer.addTo(map);

    // The locations already crossed the wire once, inside this
    // component's Livewire snapshot — read them from the component
    // instead of embedding a second copy of the payload in the page
    // (issue #776).
    const allLocations = component.get('location') ?? [];
    const byId = new Map(allLocations.map((l) => [l.id, l]));

    // Perf: memoised depth (was: a full re-scan per node — O(N·depth)).
    const depthCache = new Map();
    const depthOf = (id) => {
      if (depthCache.has(id)) {
        return depthCache.get(id);
      }
      const node = byId.get(id);
      if (!node || !node.parent_id) {
        depthCache.set(id, 0);
        return 0;
      }
      depthCache.set(id, 0); // placeholder — also breaks parent cycles
      const depth = depthOf(node.parent_id) + 1;
      depthCache.set(id, depth);
      return depth;
    };

    // Client-side filtering with the same semantics the server query had:
    // a unit is visible when it matches the selected regions AND types,
    // and every visible unit's ancestors stay visible so the connection
    // lines keep their parent markers.
    function visibleLocations() {
      const selected = (group) => new Set(
        [...document.querySelectorAll(`[data-filter-group="${group}"] input[type="checkbox"]:checked`)]
          .map((cb) => Number(cb.value)),
      );
      const regions = selected('regions');
      const types = selected('types');
      if (!regions.size && !types.size) {
        return allLocations;
      }

      const visible = new Map();
      for (const loc of allLocations) {
        if (regions.size && !regions.has(loc.region_id)) {
          continue;
        }
        if (types.size && !types.has(loc.unit_type_id)) {
          continue;
        }
        let cur = loc;
        while (cur && !visible.has(cur.id)) {
          visible.set(cur.id, cur);
          cur = byId.get(cur.parent_id);
        }
      }
      return [...visible.values()];
    }

    // Perf: chunked rendering — building ~800 markers + ~800 lines in one
    // synchronous pass froze the main thread for ~1s on load and on every
    // filter switch. Markers go first (they are what the user waits for),
    // in requestAnimationFrame slices, so the page never locks up.
    let renderToken = 0;
    function renderMarkers(locations) {
      const token = ++renderToken;
      markersLayer.clearLayers();
      linesLayer.clearLayers();

      const linePairs = [];
      for (const loc of locations) {
        if (!loc.parent_id) {
          continue;
        }
        const parent = byId.get(loc.parent_id);
        if (parent && parent.lat && parent.lng) {
          linePairs.push([loc, parent]);
        }
      }

      const MARKER_CHUNK = 250;
      const LINE_CHUNK = 400;
      let mi = 0;
      let li = 0;

      const markerStep = () => {
        if (token !== renderToken) {
          return;
        }
        const end = Math.min(mi + MARKER_CHUNK, locations.length);
        for (; mi < end; mi++) {
          const loc = locations[mi];
          L.marker(
            [loc.lat, loc.lng],
            { icon: getIcon(loc.unit_type_id) },
          )
            .bindPopup(loc.name)
            .addTo(markersLayer);
        }
        if (mi < locations.length) {
          requestAnimationFrame(markerStep);
        } else {
          requestAnimationFrame(lineStep);
        }
      };

      const lineStep = () => {
        if (token !== renderToken) {
          return;
        }
        const end = Math.min(li + LINE_CHUNK, linePairs.length);
        for (; li < end; li++) {
          const [loc, parent] = linePairs[li];
          const color = lineColors[Math.min(depthOf(parent.id), lineColors.length - 1)];
          L.polyline(
            [[loc.lat, loc.lng], [parent.lat, parent.lng]],
            { color, weight: 2, opacity: 0.7, dashArray: '6 4', renderer: lineRenderer },
          ).addTo(linesLayer);
        }
        if (li < linePairs.length) {
          requestAnimationFrame(lineStep);
        }
      };

      requestAnimationFrame(markerStep);
    }

    // Render initial locations
    renderMarkers(allLocations);

    // Filter toggles re-render locally — no Livewire round-trip.
    // Dataset guard: re-running setup on the same DOM must not stack
    // duplicate listeners on the same checkbox.
    document.querySelectorAll('.controls-panel input[type="checkbox"]').forEach((cb) => {
      if (cb.dataset.pointBound) {
        return;
      }
      cb.dataset.pointBound = '1';
      cb.addEventListener('change', () => renderMarkers(visibleLocations()));
    });
  });
}

/* ---------------- maps/unit ---------------- */

let geojsonLayers = {};
// #1188: the geometry is NOT published here. This script runs once, on the
// first render, when no filter is picked yet — so this was always an empty
// array. The unit rows (with their geometry) arrive with the
// `units-updated` event and are handed to `allUnits` by its listener below.
let allUnits = [];
let countyLayers = {};
let activeToggles = {};

function clearAllLayers(map) {
  Object.keys(geojsonLayers).forEach(function (id) {
    map.removeLayer(geojsonLayers[id]);
    delete geojsonLayers[id];
  });
}

function clearCountyLayers(map) {
  Object.keys(countyLayers).forEach(function (id) {
    map.removeLayer(countyLayers[id]);
    delete countyLayers[id];
  });
}

function showCountyBoundaries(map, counties) {
  clearCountyLayers(map);
  counties.forEach(function (c) {
    if (c.geojson && !countyLayers[c.id]) {
      try {
        const data = typeof c.geojson === 'string' ? JSON.parse(c.geojson) : c.geojson;
        countyLayers[c.id] = L.geoJSON(data, {
          style: { color: '#3b82f6', weight: 2, opacity: 0.7, fillOpacity: 0.05 },
        }).addTo(map);
      } catch (e) {
        console.error('Error adding county boundary:', e);
      }
    }
  });
}

function showUnits(map, units) {
  units.forEach(function (unit) {
    if (unit.geojson && !geojsonLayers[unit.id]) {
      try {
        const data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
        geojsonLayers[unit.id] = L.geoJSON(data, {
          style: { color: 'orange', weight: 2, opacity: 0.8, fillOpacity: 0.1 },
        }).addTo(map);
        activeToggles[unit.id] = true;
      } catch (e) {
        console.error('Error adding GeoJSON:', e);
      }
    }
  });
}

function syncToggleStates() {
  document.querySelectorAll('[wire\\:key^="unit-"]').forEach(function (el) {
    const input = el.querySelector('input[type="checkbox"]');
    if (!input) {
      return;
    }
    const key = el.getAttribute('wire:key');
    const unitId = parseInt(key.replace('unit-', ''), 10);
    if (activeToggles[unitId]) {
      input.checked = true;
    }
  });
}

function toggleGeoJsonOn(unitId) {
  const map = unitMapCurrent;
  if (!map) {
    return;
  }
  const unit = allUnits.find((u) => u.id === unitId);
  if (!unit || !unit.geojson || geojsonLayers[unitId]) {
    return;
  }
  activeToggles[unitId] = true;
  try {
    const data = typeof unit.geojson === 'string' ? JSON.parse(unit.geojson) : unit.geojson;
    geojsonLayers[unitId] = L.geoJSON(data, {
      style: { color: 'orange', weight: 2, opacity: 0.8, fillOpacity: 0.1 },
    }).addTo(map);
    if (geojsonLayers[unitId].getBounds) {
      map.fitBounds(geojsonLayers[unitId].getBounds().pad(0.1));
    }
  } catch (e) {
    console.error('Error parsing GeoJSON:', e);
  }
}

function toggleGeoJsonOff(unitId) {
  const map = unitMapCurrent;
  delete activeToggles[unitId];
  if (map && geojsonLayers[unitId]) {
    map.removeLayer(geojsonLayers[unitId]);
    delete geojsonLayers[unitId];
  }
}

function setupUnitMap() {
  if (typeof window.Alpine === 'undefined' || typeof window.Livewire === 'undefined') {
    return;
  }
  if (!document.querySelector('[data-unit-toggles]')) {
    return;
  }
  if (!unitDelegationRegistered) {
    unitDelegationRegistered = true;
    // The template's toggles used to call the window globals from an
    // `x-on:change` directive — a global-function call, dead under
    // the CSP build. Delegation replaces it (county pattern).
    document.addEventListener('change', (event) => {
      const wrap = event.target.closest('[data-unit-id]');
      if (!wrap) {
        return;
      }
      const input = wrap.querySelector('input[type="checkbox"]');
      const unitId = parseInt(wrap.dataset.unitId, 10);
      if (!input || !Number.isFinite(unitId)) {
        return;
      }
      if (input.checked) {
        toggleGeoJsonOn(unitId);
      } else {
        toggleGeoJsonOff(unitId);
      }
    });
  }
  if (!unitListenersRegistered) {
    unitListenersRegistered = true;
    Livewire.on('county-boundaries-loaded', function ({ counties }) {
      if (unitMapCurrent) {
        showCountyBoundaries(unitMapCurrent, counties);
      }
    });
    Livewire.on('units-updated', function ({ units }) {
      if (!unitMapCurrent) {
        return;
      }
      clearAllLayers(unitMapCurrent);
      allUnits = units;
      showUnits(unitMapCurrent, allUnits);
      requestAnimationFrame(syncToggleStates);
    });
  }

  // Same store guard as setupPointMap: `livewire:init` can fire before
  // `alpine:init` registers the store; the alpine:init setup call and
  // `livewire:navigated` cover those cases.
  const store = window.Alpine?.store?.('map');
  if (!store) {
    return;
  }

  // Issue #028 — bind to the live map the store owns. onReady() replaces the
  // old `waitForMap`, whose "window.map exists" check a previous page's
  // detached instance satisfied, and it also removes the script-ordering race
  // with maps.map.
  store.onReady(function (map) {
    // Fresh state per map, like the original per-evaluation state.
    geojsonLayers = {};
    allUnits = [];
    countyLayers = {};
    activeToggles = {};
    unitMapCurrent = map;

    window.toggleGeoJsonOn = function (unitId) {
      toggleGeoJsonOn(unitId);
    };
    window.toggleGeoJsonOff = function (unitId) {
      toggleGeoJsonOff(unitId);
    };
  });
}

/* ---------------- units/map (boundary editor) ---------------- */

function readUnitMapData() {
  const block = document.getElementById('unit-map-data');
  if (!block) {
    return { tileTemplate: '', geojson: null };
  }
  try {
    const parsed = JSON.parse(block.textContent || '{}');
    return { tileTemplate: parsed.tileTemplate || '', geojson: parsed.geojson ?? null };
  } catch (e) {
    return { tileTemplate: '', geojson: null };
  }
}

function setupUnitsEditMap() {
  const container = document.getElementById('unitMap');
  if (!container || typeof L === 'undefined') {
    return;
  }
  // Leaflet throws on a second L.map for the same element; setup
  // re-runs on every navigation, so skip an initialised container.
  if (container._leaflet_id) {
    return;
  }
  const { tileTemplate, geojson: initialGeojson } = readUnitMapData();

  const component = (() => {
    const root = container.closest('[wire\\:id]');
    return root && typeof window.Livewire !== 'undefined'
      ? window.Livewire.find(root.getAttribute('wire:id'))
      : null;
  })();

  function initUnitMap() {
    const unitMap = L.map('unitMap').setView([36.558188, 48.716125], 8);

    L.tileLayer(tileTemplate, {
      attribution: '&copy; Health-Dashboard',
      className: 'map-tiles',
    }).addTo(unitMap);

    const drawnItems = new L.FeatureGroup();
    window._drawnItems = drawnItems;
    unitMap.addLayer(drawnItems);

    let existingLayer = null;

    if (initialGeojson) {
      try {
        const data = typeof initialGeojson === 'string' ? JSON.parse(initialGeojson) : initialGeojson;

        // #702: boundaries are stored as MultiPolygon (PostGIS) and
        // Boundary::getGeojsonAttribute() returns ST_AsGeoJSON's BARE
        // geometry ({type, coordinates}) — not a GeoJSON Feature. Two bugs
        // followed from that:
        //   1. L.geoJSON() was handed a geometry with no `geometry` key, so
        //      the layer was empty and fitBounds threw "Bounds are not valid",
        //      leaving the draw toolbar permanently disabled.
        //   2. Even when a layer did load, MultiPolygon gave it THREE levels
        //      of nesting ([[ring]] -> [1] => [1] => [19] => LatLng).
        //      L.Edit.Poly only understands two ([ring]), so it enabled
        //      without error but rendered zero vertex handles and the
        //      boundary was not editable.
        // Normalise both: accept a bare geometry or a Feature, and unwrap
        // the single MultiPolygon to a plain Polygon so L.Edit.Poly works.
        let geometry = (data && data.geometry) ? data.geometry : data;
        if (geometry && geometry.type === 'MultiPolygon' && Array.isArray(geometry.coordinates)) {
          geometry = {
            type: 'Polygon',
            coordinates: geometry.coordinates[0] || [],
          };
        }

        existingLayer = L.geoJSON({ type: 'Feature', properties: {}, geometry }, {
          style: { color: '#f59e0b', weight: 3, opacity: 0.8, fillOpacity: 0.15 },
        }).addTo(unitMap);

        // #702: the draw toolbar's edit/remove tools only operate on layers
        // inside `drawnItems`. The saved boundary was added straight to the
        // map, so it got no edit handles and the toolbar could not delete it — only
        // the bottom "حذف مرز" button worked.
        //
        // Add the INDIVIDUAL polygon layers (eachLayer), never the L.GeoJSON
        // group itself: updateGeojson() iterates getLayers() and only handles
        // L.Polygon instances.
        existingLayer.eachLayer(function (layer) {
          drawnItems.addLayer(layer);
        });

        unitMap.fitBounds(drawnItems.getBounds());

        // #702: seed _mapGeojson from the loaded boundary. Without this,
        // _mapGeojson stayed undefined until the user drew something, and
        // saveMapBoundary() reads that as "nothing to save" and routed to
        // deleteBoundary() — so simply opening a unit with a boundary and
        // pressing ذخیره deleted it.
        updateGeojson();
      } catch (e) {
        console.error('Error loading existing boundary:', e);
      }
    }

    const drawControl = new L.Control.Draw({
      edit: { featureGroup: drawnItems, remove: true },
      draw: {
        polygon: true,
        polyline: false,
        rectangle: false,
        circle: false,
        marker: false,
        circlemarker: false,
      },
    });
    unitMap.addControl(drawControl);

    unitMap.on('draw:created', function (event) {
      // #702: a unit has exactly one boundary, so a newly drawn polygon
      // replaces the previous one. The old layer now lives inside
      // `drawnItems`, so clearing the group replaces the previous
      // "remove existingLayer from map" logic.
      drawnItems.clearLayers();

      let layer = event.layer;
      if (layer instanceof L.Rectangle) {
        layer = L.polygon(layer.getLatLngs()[0], layer.options);
      }

      drawnItems.addLayer(layer);
      updateGeojson();
    });

    unitMap.on('draw:edited', function () {
      updateGeojson();
    });

    unitMap.on('draw:deleted', function () {
      updateGeojson();
    });
  }

  function updateGeojson() {
    // Access drawnItems from the closure - use global reference
    const layers = window._drawnItems ? window._drawnItems.getLayers() : [];
    if (layers.length === 0) {
      window._mapGeojson = null;
      return;
    }
    const coords = [];
    layers.forEach(function (layer) {
      if (layer instanceof L.Polygon && !(layer instanceof L.Rectangle)) {
        // #702: getLatLngs() is [ring] for a plain polygon but [[ring]] for
        // one built from a MultiPolygon geometry. Normalise to the real
        // ring before mapping coords, otherwise ring.map() walks arrays
        // instead of LatLngs and yields [undefined, undefined, ...].
        let latlngs = layer.getLatLngs();
        let ring = latlngs;
        while (Array.isArray(ring) && Array.isArray(ring[0])) {
          ring = ring[0];
        }
        if (!Array.isArray(ring) || ring.length === 0) {
          return;
        }

        const pts = ring.map(function (ll) { return [ll.lng, ll.lat]; });
        if (pts.length > 0) {
          const first = pts[0];
          const last = pts[pts.length - 1];
          if (first[0] !== last[0] || first[1] !== last[1]) {
            pts.push(first);
          }
        }
        if (pts.length > 0) {
          coords.push([pts]);
        }
      }
    });
    if (coords.length === 0) {
      window._mapGeojson = null;
      return;
    }
    window._mapGeojson = JSON.stringify({
      type: 'Feature',
      properties: {},
      geometry: { type: 'MultiPolygon', coordinates: coords },
    });
  }

  window.saveMapBoundary = function () {
    if (window._mapGeojson) {
      component?.call('saveBoundary', window._mapGeojson);
    } else {
      component?.call('deleteBoundary');
    }
  };

  const saveMapBoundaryBtn = document.getElementById('saveMapBoundaryBtn');
  if (saveMapBoundaryBtn && !saveMapBoundaryBtn.dataset.bound) {
    saveMapBoundaryBtn.dataset.bound = '1';
    saveMapBoundaryBtn.addEventListener('click', window.saveMapBoundary);
  }

  if (typeof window.Livewire !== 'undefined' && !boundaryListenerRegistered) {
    boundaryListenerRegistered = true;
    window.Livewire.on('boundaryUpdated', function () {
      window.location.reload();
    });
  }

  // Wait for the #unitMap DOM element to exist
  if (document.getElementById('unitMap')) {
    initUnitMap();
  } else {
    let tries = 0;
    const waitForEl = setInterval(() => {
      tries++;
      if (document.getElementById('unitMap')) {
        clearInterval(waitForEl);
        initUnitMap();
      } else if (tries > 50) {
        clearInterval(waitForEl);
        console.error('Map container #unitMap not found within 10s');
      }
    }, 200);
  }
}

export function setupMapLayers() {
  setupPointMap();
  setupUnitMap();
  setupUnitsEditMap();
}

document.addEventListener('livewire:init', setupMapLayers);
document.addEventListener('livewire:navigated', setupMapLayers);
