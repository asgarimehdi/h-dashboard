/**
 * maps/map.blade.php component logic (#1352 CSP-compat).
 *
 * WHY THIS EXISTS
 * ---------------
 * The component's `init()`/`destroy()` used to live in an `x-data` object
 * literal in the Blade template. The CSP-safe Alpine build evaluates
 * directive expressions with a small interpreter that rejects method
 * shorthand AND `function` expressions in object literals, so the data
 * object evaluated EMPTY and `init()` never ran — the map silently never
 * got created (measured: 24/25 maps specs failing, container absent).
 *
 * The logic therefore lives here, in plain JS, registered as an
 * `Alpine.data()` component on `alpine:init` (same static-import
 * discipline as the map store in bootstrap.js). The template carries
 * only `x-data="mapHost"` plus data attributes for the server config
 * (view/zoom/tile URL), which `init()` reads from `this.$el.dataset`.
 *
 * The placement rules in maps/map.blade.php's header comment still hold:
 * the `x-data` element must not be the component root, and `$refs.map`
 * resolves across the `wire:ignore` boundary.
 */

export function registerMapHost(Alpine) {
  Alpine.data('mapHost', () => ({
    _invalidate: null,

    init() {
      const el = this.$el;
      let view = [36.558188, 48.716125];
      try {
        view = JSON.parse(el.dataset.view || '');
      } catch (e) {
        // Keep the default view; a bad data-view must not blank the map.
        console.warn('[map] invalid data-view, using default', e);
      }
      const zoom = parseInt(el.dataset.zoom || '8', 10) || 8;
      const tileUrl = el.dataset.tileUrl || undefined;

      this.$store.map.configure({
        view,
        zoom,
        ...(tileUrl ? { tileUrl } : {}),
      });

      // Keep the raw instance in a closure local — assigning it to
      // this.map would put it back through Alpine's reactivity
      // (see the note in map-store.js).
      const map = this.$store.map.use(this.$refs.map);

      if (map) {
        // Issue (map width): Leaflet captures dimensions at
        // construction, so a page/layout still settling (SPA
        // navigation, fonts, hidden containers) can lock in a
        // smaller width and render half-page.
        this._invalidate = () => map.invalidateSize();
        setTimeout(this._invalidate, 100);
        window.addEventListener('resize', this._invalidate);
      }
    },

    destroy() {
      if (this._invalidate) {
        window.removeEventListener('resize', this._invalidate);
      }
      this.$store.map.release(this.$refs.map);
    },
  }));
}
