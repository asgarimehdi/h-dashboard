# Leaflet Maps & GIS

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

## Leaflet map lifecycle (issue #028)

> **Tile template comes only from `TILE_URL_TEMPLATE`.** `TILE_SERVER_IP` / `TILE_SERVER_PORT` /
> `TILE_SERVER_SCHEME` build `map.tile_url`, which has no consumer — changing them does nothing.
> → `docs/agents/offline.md`.

The Leaflet instance lives in a **module-scope variable in `resources/js/map-store.js`** (registered
in `resources/js/bootstrap.js`) — **not** on `window.map`, and not inside the reactive Alpine store
either. `Alpine.store('map')` holds the config, the callbacks, and the lifecycle API
(`configure()` / `use()` / `release()` / `onReady()`).

- **The only `L.map()` call is inside `map-store.js`'s `use()`.** `map.blade.php` never calls it —
  its `x-data` calls `configure()` + `use()` in `init()` and `release()` in `destroy()`.
- Host pages (`maps/point`, `maps/county`, `maps/unit`, `maps/route`, `maps/route2`,
  `reports/map-no-boundary`) call `Alpine.store('map').onReady(cb)` and attach to **the instance the
  callback receives**. They must never wait on "does a map exist" and must never create one.
- **County data on `maps/unit` is scope-filtered (#1032).** The county list (`mount()`) and the
  county geometry (`showSelectedCounties()`) are both filtered by `$accessibleRegionIds`, derived
  from `AccessService::accessibleUnitIds()` — the same pattern as `maps/county` — and the access
  scope is part of **both** cache keys. Fail-closed: an out-of-scope selection resolves to empty.
- **Plugin CSS is page-scoped (#1018).** The base Leaflet CSS stays global; `leaflet.draw.css`
  loads only on `units/map` and `leaflet-routing-machine.css` only on `maps/route` and
  `maps/route2`, each via `@push('styles')`, rendered by the layout's `@stack('styles')` after
  `@vite`. Do not move plugin CSS back into the global head.

Why it changed: the instance used to live on `window.map`, which is never cleared, so SPA navigation
left the **previous page's detached instance** in place. Host pages waited for `window.map` to exist
— a condition a detached instance satisfies — bound their layers to it, and then `initMap()` built a
fresh map and wiped the layers. Measured **0/6** marker renders on `/maps/point` when reached by
clicking the sidebar, versus 783 on a direct load.

`onReady()` exists because **script execution order between two Livewire components is not
guaranteed** (`@script` runs once per component instance — `evaluateScripts` in `livewire.esm.js`).
It queues the callback and flushes it when the map exists, so neither component has to know which
runs first.

Three rules, each of which cost real debugging time:

| Rule | Why |
|---|---|
| The `x-data` element must **not** be the component root | Livewire puts `wire:id` on the **outermost** element of a component's markup. An `x-data` there is claimed by Livewire and its `init()` never runs, so the map silently never appears. `map.blade.php` wraps it in a plain `<div>` for this reason. |
| The `import` of `map-store` in `bootstrap.js` must be **static** | A dynamic `import()` resolves in a microtask, i.e. **after** Livewire's `start()` already dispatched `alpine:init`. The store then registers too late, every `x-data` `init()` finds no store, and **all maps render blank with no console error**. |
| Register on `alpine:init`, not at module evaluation | `window.Alpine` does not exist when the module runs, and the event always fires before the first `x-data`. |

`$refs.map` resolves fine **across** the `wire:ignore` boundary, so the container keeps `id="map"`
and `class="h-[80lvh] rounded"` — the E2E map specs assert on both plus `clientWidth > 400`, which is
why `invalidateSize()` and the `resize` listener are preserved.

> **Out of scope, deliberately untouched:** `units/map.blade.php` (`/units/{id}/map`) owns a
> separate instance on `#unitMap`, whose E2E spec depends on `window._drawnItems`. Do not
> migrate it without a separate decision. (`maps/interactive` was removed as dead code in
> issue #775.) `map/map-dashboard.blade.php` (`/map`) used to own one too; it was migrated
> onto the shared component on 2026-10-02 at Mehdi's decision — it embeds
> `livewire:maps.map`, holds the instance and its layer groups in closure locals inside its
> Alpine factory (never on the reactive data object), and attaches via `onReady`.

## Gotchas (transplanted from the former `## Gotchas Quick Reference`)

| Gotcha | Details |
|---|---|
| Map container | Do NOT wrap `maps.map` in Bootstrap `container` class — use `relative` |
| Leaflet.Draw featureGroup | `L.Control.Draw({ edit: { featureGroup } })` only enables edit/remove when `featureGroup.getLayers().length > 0` — `_checkDisabled` in `public/js/leaflet/leaflet.draw.js` adds `.leaflet-disabled` otherwise. A layer added straight to the map is invisible to the toolbar: put it in the FeatureGroup with `eachLayer(l => drawnItems.addLayer(l))` (individual layers, never the `L.GeoJSON` group — `updateGeojson()` only serialises `L.Polygon`) |
| `units.boundary_id` is ON DELETE CASCADE | The FK is `ON DELETE CASCADE`. Boundary deletion (`units/map::deleteBoundary()`) carries only a scope check — no `UnitDeletionService` is involved on that path (its impact check serves **unit** deletion and counts children/tickets/persons/hardware/todos/accounts, never boundaries). A leaf unit with zero row counts can still have a boundary, and deleting that boundary first is the safe order. Always `$unit->update(['boundary_id' => null])` BEFORE deleting the boundary row; deleting the boundary row with the FK still attached cascades the unit row away with it. The misleading word in issue #702's original gotcha was "subtree": `units_parent_fk` is `ON DELETE RESTRICT`, so child units block the delete (with a 23503) — they are never deleted with it. The real damage is the LEAF case, where the delete does not even need a boundary involved: the unit row is deleted successfully, `persons.u_id` (itself `ON DELETE SET NULL` on `persons_u_id_foreign_units`) is nullified, and `user_units` rows are discarded — those people's hardware drops out of every `HardwareAuditScope` view while the people themselves stay (issue #702) |
| `Boundary::geojson` is a BARE geometry | `getGeojsonAttribute()` returns `ST_AsGeoJSON(...)` = `{"type":"MultiPolygon","coordinates":[…]}` — there is NO `geometry` key, so it is not a GeoJSON Feature. Hand `L.geoJSON` a Feature you build yourself and unwrap `MultiPolygon` → `Polygon` first, or `fitBounds` throws `Bounds are not valid` inside the `try`/`catch`, the layer never loads, and the draw toolbar stays disabled (issue #702) |
| `getLatLngs()` nesting depth | A plain `L.Polygon` is `[ring]`; one built from a `MultiPolygon` is `[[ring]]` — three levels. `L.Edit.Poly` only reads two, so `editing.enabled()` returns `true` with **no error** while `.leaflet-editing-icon` count is `0` and the ring serialises as `[undefined, undefined, …]`. Normalise with `while (Array.isArray(ring[0])) ring = ring[0]` before mapping coords |
| Leaflet is minified in E2E | `layer.constructor.name` is `"e"`, never `"Polygon"`. In Playwright assert `layer instanceof window.L.Polygon` — never match a constructor-name string. Keep the real names in the returned payload so a failure prints what it got |
| `_mapGeojson` must be seeded on load | `saveMapBoundary()` calls `deleteBoundary()` whenever `_mapGeojson` is falsy, so a boundary that is never re-serialised on page load is destroyed by a plain "ذخیره" click. Call `updateGeojson()` right after loading the saved layer (issue #702) |
| Playwright on map pages | Never `await networkidle` — Leaflet keeps fetching tiles so it never goes idle and the wait times out. Wait for `#unitMap` + `.leaflet-draw-edit-edit` instead |
| Leaflet + Alpine reactivity | Never put a Leaflet instance (map or layers) in Alpine reactive state — `x-data` or `Alpine.store`. Alpine deep-wraps it in proxies and Leaflet's identity-based listener cleanup (`===` in `Marker.onRemove`) silently leaks `zoomanim` handlers; after `clearLayers()` the next zoom throws `_latLngToNewLayerPoint` and freezes markers (issue #769). Keep the instance in module scope / closure locals — see `resources/js/map-store.js` |
| Disease layer is read-only GeoJSON | `/map`'s «بیماری‌ها» layer consumes `GET /api/gis/disease-map`, which already returns a standard `FeatureCollection` — never build one from `Boundary::geojson` (bare geometry, `MultiPolygon` unwrap) and never touch `_mapGeojson` / `saveMapBoundary`; those belong to the `units/map` edit flow (#702). Below the zoom switch `properties.level` is **`county` or `province`** (not `region`) and `meta.level` may be `mixed`/`none`; quantiles arrive as `properties.class` + `meta.breaks`, so the client must not re-derive them (#1245, #1246) |
| Disease layer has two groups + two panes | `diseaseRegions` (pane z-index 410) and `diseaseUnits` (620) crossfade at `ZOOM_SWITCH=9`; the hidden pane gets `pointer-events: none` so its popups cannot be clicked. `LayerGroup.clearLayers()` is **not recursive** — clear both sibling groups explicitly on toggle-off; a parent group would only detach its children and keep their data (#1246) |
