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

export function registerMapStore(Alpine) {
  Alpine.store(MAP_ID, {
    instance: null,
    container: null,
    config: {},
    pending: [],

    /** Called by the map component so late `use()` calls build the right map. */
    configure(config = {}) {
      this.config = { ...this.config, ...config };
    },

    /** The live instance, or null. Never returns a detached map. */
    get() {
      if (!this.instance) return null;
      return containerOf(this.instance) === this.container ? this.instance : null;
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

      if (this.instance && containerOf(this.instance) === el) {
        return this.instance; // already ours — reuse
      }

      // Either nothing yet, or a stale instance from a previous page.
      if (this.instance) {
        this.release(null, { force: true });
      }

      const view = this.config.view ?? [36.558188, 48.716125];
      const zoom = this.config.zoom ?? 8;
      const instance = L.map(el).setView(view, zoom);

      L.tileLayer(
        this.config.tileUrl ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
          attribution: this.config.attribution ?? '&copy; Health-Dashboard',
          className: 'map-tiles',
        },
      ).addTo(instance);

      this.instance = instance;
      this.container = el;

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
          callback(this.instance);
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
      if (!this.instance) return;
      if (!force && el && this.container !== el) return;

      destroyInstance(this.instance);
      this.instance = null;
      this.container = null;
    },
  });
}