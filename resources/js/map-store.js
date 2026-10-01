/**
 * Leaflet lifecycle owner (issue #028).
 *
 * The map instance used to live on `window.map`, which is never cleared, so a
 * SPA navigation left the PREVIOUS page's instance in place. Host pages waited
 * for "window.map exists", which a detached instance satisfies — they bound
 * their layers to a map that `initMap()` then replaced and wiped. Measured 0/6
 * marker renders when /maps/point was reached by clicking the sidebar.
 *
 * This store gives the instance an owner and a lifecycle:
 *   configure(opts) → the map component publishes its tile/view config
 *   use(el)         → build or reuse a map bound to `el`, returns the instance
 *   onReady(cb)     → run cb with a live map, now or once one exists
 *   release(el)     → destroy it, so nothing stale can be picked up later
 *
 * Only resources/views/livewire/maps/map.blade.php may create a Leaflet map.
 * A host page calls `onReady()` and attaches to the instance it receives — it
 * must never create one, and must never wait on a "map exists" flag, because
 * script execution order between the two components is NOT guaranteed.
 */

const MAP_ID = 'map';

function isConnected(el) {
  return !!el && document.body.contains(el);
}

function containerOf(instance) {
  return instance && typeof instance.getContainer === 'function'
    ? instance.getContainer()
    : null;
}

function destroyInstance(instance) {
  try {
    // Drop handlers first: a removed instance that still reacts to window
    // resize would keep firing against a detached container.
    instance.off();
    instance.remove();
  } catch (e) {
    console.warn('[map] failed to tear down the map instance', e);
  }
}

/*
 * Issue #769 — the Leaflet instance must NEVER pass through Alpine's
 * reactivity. Alpine deep-wraps store properties in reactive proxies, so
 * `store.instance` came back as a Proxy of the L.Map. Every addLayer /
 * removeLayer / on / off then flowed through that proxy, and its set traps
 * wrapped stored layers in nested proxies: map._layers[id] ended up holding
 * a proxy of the marker while the zoomanim listener ctx was the raw marker
 * (or vice versa — either direction breaks ===). Marker.onRemove's
 * map.off('zoomanim', this._animateZoom, this) relies on strict identity in
 * Leaflet's _listens(), so it silently matched nothing and every
 * clearLayers() leaked ~N orphaned zoomanim listeners. On the next zoom the
 * orphans fire with ctx._map === null, _animateZoom throws
 * (reading '_latLngToNewLayerPoint' of null), and the exception aborts the
 * whole zoom animation — every marker freezes in place.
 *
 * The instance therefore lives in module scope, outside the reactive store.
 * The store keeps only plain data (config, pending callbacks) and the
 * lifecycle API; get()/use()/onReady() hand out the raw instance.
 */
let instance = null;
let containerEl = null;

export function registerMapStore(Alpine) {
  Alpine.store(MAP_ID, {
    config: {},
    pending: [],

    /** Called by the map component so late `use()` calls build the right map. */
    configure(config = {}) {
      this.config = { ...this.config, ...config };
    },

    /** The live instance, or null. Never returns a detached map. Never a proxy. */
    get() {
      if (!instance) return null;
      return containerOf(instance) === containerEl ? instance : null;
    },

    /**
     * Return a Leaflet map bound to `el`, creating one when needed.
     * Returns null (with a warning) when `el` is not in the document — a loud
     * no-op, never a silent bind to the wrong map.
     */
    use(el) {
      if (!isConnected(el)) {
        console.warn('[map] container is not in the document; no map created', el);
        return null;
      }

      if (instance && containerOf(instance) === el) {
        return instance; // already ours — reuse
      }

      // Either nothing yet, or a stale instance from a previous page.
      if (instance) {
        this.release(null, { force: true });
      }

      const view = this.config.view ?? [36.558188, 48.716125];
      const zoom = this.config.zoom ?? 8;
      instance = L.map(el).setView(view, zoom);

      L.tileLayer(
        this.config.tileUrl ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
          attribution: this.config.attribution ?? '&copy; Health-Dashboard',
          className: 'map-tiles',
        },
      ).addTo(instance);

      containerEl = el;

      this.flush();
      return instance;
    },

    /**
     * Run `callback(instance)` with a live map: immediately when one exists,
     * otherwise as soon as the map component creates it. This removes the
     * script-ordering race between the host page and maps.map entirely.
     */
    onReady(callback) {
      const live = this.get();
      if (live) {
        callback(live);
        return;
      }
      this.pending.push(callback);
    },

    flush() {
      const queued = this.pending;
      this.pending = [];
      queued.forEach((callback) => {
        try {
          callback(instance);
        } catch (e) {
          console.error('[map] an onReady consumer threw', e);
        }
      });
    },

    /**
     * Destroy the instance. `el` guards against a late release from a page we
     * already navigated away from killing the NEW page's map; pass
     * { force: true } to destroy regardless.
     */
    release(el, { force = false } = {}) {
      if (!instance) return;
      if (!force && el && containerEl !== el) return;

      destroyInstance(instance);
      instance = null;
      containerEl = null;
    },
  });
}