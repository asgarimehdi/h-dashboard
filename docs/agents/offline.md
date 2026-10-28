# Offline & external runtime dependencies

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-28; that file keeps the one-line rules and
| the map of where each topic lives.
| Audited on `beta` @ `a7ce9d17` (issue #1265, phase 1 — documentation only).

## Verdict

- **Browser runtime:** the only real internet dependency is the **map basemap tile**,
  served by OpenStreetMap through `TILE_URL_TEMPLATE`. Leaflet, draw, routing-machine,
  jalalidatepicker, FullCalendar, Highcharts, the Vazirmatn font, the Vite bundle and
  Livewire are all served by this application. One hidden exception: `/op`
  (`resources/views/op/index.php`) pulls React, ReactDOM and axios from cdnjs — and that
  route exists **only** when `APP_ENV=local` (`routes/web.php:204`).
- **Server egress:** nothing reaches the public internet by default. Zabbix
  (`ZABBIX_URL`), OSRM (`ROUTING_SERVER_*`) and Nominatim (`GEOCODING_SERVER_*`) are
  expected to be internal (`127.0.0.1`); Sentry and mail only egress when configured.
- **Build / install:** `composer install`, `npm install`, `docker pull` and
  `npx playwright install` need the internet. `npm run build` and `php artisan` do not.
  These are **not** runtime dependencies and must never be counted as such.

> **"Fully offline" is not true today.** With no internet the pages still render, but the
> map basemap is grey/empty. Rendering is not the same as being offline.

## Browser runtime loads

Every row is a **runtime** load. The **Override** column is the knob that changes the
destination without touching code. This table is the allowlist a future guard test
(`tests/Feature/NoExternalRuntimeAssetsTest.php`, separate issue) should pin — so each
row carries `file:line` and local/external, never a general description.

| # | Location | Type | Destination | Scope | Override |
|---|---|---|---|---|---|
| 1 | `resources/views/components/layouts/app.blade.php:11` | font (preload) | `/fonts/Vazirmatn-Regular.woff2` | local | — |
| 2 | `app.blade.php:12` | JS + CSS | `@vite` → `public/build` | local | — |
| 3 | `app.blade.php:18-19` | stylesheet | `css/leaflet/leaflet.css`, `css/other/jalalidatepicker.min.css` | local | — |
| 4 | `app.blade.php:401` | CDN script | `cdn.jsdelivr.net/npm/sweetalert2@11` | **external, commented out — never loads** | — (needs a code change) |
| 5 | `app.blade.php:402-406` | service worker | `/sw.js` | local (no cache/offline — see gaps) | — |
| 6 | `resources/js/map-store.js:107-113` | map tile | `config('map.tile_url_template')` | **external (OSM by default)** | `TILE_URL_TEMPLATE` |
| 7 | `resources/js/map-store.js:108` | map tile (fallback) | literal `https://{s}.tile.openstreetmap.org/...` | **external, dead but risky** | `TILE_URL_TEMPLATE` (always set today; phase 2 removes the literal) |
| 8 | `resources/views/livewire/maps/map.blade.php:12,56` | map tile | `tileUrl` handed to the store; OSM literal as the `config()` fallback | external by default | `TILE_URL_TEMPLATE` |
| 9 | `resources/views/livewire/units/map.blade.php:229,234` | map tile | `config('map.tile_url_template')` | **external (OSM by default)** | `TILE_URL_TEMPLATE` |
| 10 | `config/map.php:7-20` | definition | `TILE_SERVER_IP/PORT/SCHEME`, `tile_url` (no consumer), `tile_url_template` | source of truth | env |
| 11 | `resources/views/livewire/maps/route.blade.php:64-65,83` | JS + fetch | Leaflet/LRM local; OSRM `config('map.routing_url')`; dead OSM literal at `:13` | local/internal (OSM literal unused) | `ROUTING_SERVER_*` |
| 12 | `resources/views/livewire/maps/route2.blade.php:97-98,156,181` | JS + fetch | Leaflet/LRM local; geocoding `config('map.geocoding_url')`; OSRM; dead OSM literal at `:19` | local/internal (OSM literal unused) | `GEOCODING_SERVER_*`, `ROUTING_SERVER_*` |
| 13 | `resources/views/livewire/map/map-dashboard.blade.php:614,672,914,924` | fetch | `/api/gis/*` (same origin) | local | — |
| 14 | `resources/views/livewire/it/network-traffic-chart.blade.php:130`, `it/wireless.blade.php:174` | fetch | `/api/zabbix/*` (same origin) | local | — |
| 15 | `resources/views/livewire/todo/todo.blade.php:599` | JS | `js/other/full-calendar.min.js` | local | — |
| 16 | `resources/views/livewire/dashboard.blade.php:666-667`, `reports/advanced.blade.php:478-479`, `reports/{persons,units,todos}.blade.php`, `hr/dashboard.blade.php:168`, `it/network-traffic-chart.blade.php:52` | JS | `js/chart/highcharts.js` + `accessibility.js` | local | — |
| 17 | `resources/views/op/index.php:525-527` | CDN scripts | `cdnjs.cloudflare.com` → React 18 / ReactDOM 18 / axios | **external — `/op`, local-only** | — (needs a code change; separate issue) |
| 18 | `public/js/leaflet/leaflet-routing-machine.min.js` | library default | `router.project-osrm.org`, `api.mapbox.com` | external defaults, overridden on both pages via `serviceUrl` | `ROUTING_SERVER_*` |
| 19 | `public/js/chart/exporting.js` | library default | `export-svg.highcharts.com` | **external default, never loaded** | — (file is not included anywhere) |

Verified absences (re-run the greps below): no `<iframe>`, `<object>`, `<embed>`,
`<video>`, `<audio>`, external image, `preconnect`/`dns-prefetch`, or `url(http…)` in
CSS. The build output contains only two host strings — `http://www.w3.org` (XML
namespace) and `https://tailwindcss.com` (CSS comment) — no external load. The bundled
routing-machine has **zero** occurrences of `nominatim`, so `L.Routing.control` never
calls an external geocoder by default. There is no broadcasting/WebSocket; every XHR is
same-origin.

## Offline matrix

Two different questions hide behind "does it work offline":

- **(a) Internet down, application server reachable** — the real hospital deployment
  case.
- **(b) No network at all** (fully offline laptop): the app is server-rendered and the
  service worker caches nothing, so **no page loads at all**. Phase 2 does not fix this
  either — the app is not a PWA.

| Page group | Works in mode (a) | Empty / broken |
|---|---|---|
| Dashboard, lists, forms, tickets, settings, search | fully | — |
| Reports (Highcharts, jalalidatepicker, FullCalendar) | fully | — |
| 6 non-routing map pages: `/maps/county`, `/maps/unit`, `/maps/point`, `/map`, `/reports/map-no-boundary`, `/units/{id}/map` | UI, pan/zoom, popup, GeoJSON layers from the server, draw | **basemap grey/empty** (tiles from OSM) |
| `/maps/route` | route if the internal OSRM is up | no basemap; without internal OSRM the route is not computed (unrelated to the internet) |
| `/maps/route2` | route + geocoding if internal OSRM/Nominatim are up | no basemap; without internal Nominatim address search does not work |
| `/op` (`APP_ENV=local` only) | HTML shell only | **blank page** — React/axios never arrive from the CDN |
| Browser notifications | if HTTPS and the tab is open | on HTTP the service-worker registration fails and is swallowed silently |

## Server egress and build dependencies

**Not runtime.** These never appear in the browser's network panel.

| Destination | Location | Needs the internet? |
|---|---|---|
| Zabbix API | `config/services.php:38` → `ZABBIX_URL` | no — internal network |
| Sentry | `config/sentry.php`, `SENTRY_LARAVEL_DSN` | only when a DSN is set (empty by default) |
| Mail | `MAIL_*` | only when mail sending is enabled |
| OSRM / Nominatim | `config/map.php:22-40` | no — `127.0.0.1` |

**Build / install only:** `composer install`, `npm install`, `docker pull` (postgis,
redis), `npx playwright install`. `npm run build` and `php artisan` run offline; the
fonts are committed under `public/fonts/`.

## Reproducing the audit

```bash
# 1) the only real external loads in the views
grep -rnE "(src|href)=[\"'](https?:)?//" resources/views

# 2) every external URL in shipped code (no vendor/build)
grep -rnE "https?://" resources/views resources/js resources/css config

# 3) tile consumers (skip bootstrap/cache — it is an artifact)
grep -rnE "tile_url_template|tile_url|tile_server" config resources app routes tests

# 4) prove tile_url has no consumer
#    NOTE: the plan's spelling `grep -rn "map[.]tile_url" ...` also matches
#    `map.tile_url_template` (tile_url is a prefix of it) and is therefore NOT empty.
#    Add a word boundary — underscores are word characters, so \b excludes _template:
grep -rnE "map[.]tile_url\b" app resources routes tests config   # expected: empty

# 5) network defaults inside vendored libraries
grep -o -E "router[.]project-osrm[.]org|api[.]mapbox[.]com" public/js/leaflet/leaflet-routing-machine.min.js | sort -u
grep -c -i nominatim public/js/leaflet/leaflet-routing-machine.min.js   # expected: 0

# 6) hosts inside the build
grep -rhoE "https?://[a-zA-Z0-9._-]+" public/build/assets | sort -u

# 7) service worker
grep -nE "addEventListener|caches|fetch" public/sw.js
```

Expected: (1) exactly four lines — one commented CDN line plus three `op/index.php`
scripts; (2) a longer list, but every hit is a comment, an XML namespace, a
documentation/attribution link (not a resource load) or a config default; (4) empty with
`\b`; (5) two lines; (7) zero `fetch`/`caches`.

### Manual offline review (performed 2026-10-28)

Chromium (headless) against the local stack with every non-`127.0.0.1` request aborted,
after a hard load of each page:

- Dashboard, lists, tickets, todo, settings, search, reports: **zero external requests,
  zero console errors**.
- The eight map pages: **only `*.tile.openstreetmap.org` requests fail**; everything
  else is 200. `Alpine.store('map').get()` is non-null on the shared-component pages;
  `/units/{id}/map` keeps its own `#unitMap` + `window._drawnItems`.
- `/op` in `APP_ENV=local`: the three cdnjs scripts fail and React throws
  `React is not defined`, leaving the root empty — the deliberate witness.
- `/maps/route`: OSRM at `127.0.0.1:5000` is refused in this environment (local, not
  internet); `/it/networks` and `/it/wireless` return 503 while Zabbix is down (local,
  not internet).

## Gaps / unverified

- **Live offline review** — done on 2026-10-28 with the method above. It confirmed the
  code-read matrix; no discrepancy to correct.
- **On-prem tile server** — no address, port or path exists anywhere in the repo (only
  `config/map.php` and the `.env*` defaults). Phase 2 steps stay conditional until it is
  specified.
- **`/op` in production** — local-only by code (`app()->isLocal()`), but if a deployment
  ever sets `APP_ENV=local`, this page breaks without the internet. Recorded, not fixed.
- **CDN/proxy in front of the deployment** — no nginx config in the repo. A layer outside
  the repo that injects assets is invisible to this audit.
- **Leaflet behaviour when the tile server is down** — inferred from the code (no
  fallback logic): the basemap stays grey. The exact `tileerror` behaviour was not
  exercised; re-check during the phase 2 review.
- **Real request logs to OSM** — no access to deployment logs. "Tiles come from OSM
  today" rests on `config('map.tile_url_template')` and `.env`, not on observed
  production traffic.
- **`public/sw.js` does not make the app offline-capable.** It only handles
  `install/activate/push/notificationclick` — no `fetch` handler and no cache. Nothing
  calls `pushManager.subscribe`, so it is effectively inert; registration on plain HTTP
  fails and `app.blade.php:405` swallows it with `catch(() => {})` (and
  `install-guid.md:135` suggests `APP_URL=http://...`).
- **Tile attribution is not OSM's.** `map-store.js:110` and `units/map.blade.php:235`
  render `&copy; Health-Dashboard`. OSM requires "© OpenStreetMap contributors"; this is
  an attribution-policy violation while OSM tiles are in use. Phase 2 must decide the
  self-hosted server's attribution text and data licence.
- **CSP is Report-Only** (`app/Http/Middleware/SecurityHeaders.php:25`): `img-src 'self'
  data: blob: https:` and `connect-src 'self'`. `https` tiles pass today, but an `http`
  tile is only reported; if CSP is ever enforced, `http` tiles and the geocoding fetch
  to `127.0.0.1:8088` are blocked. The exact header is pinned in
  `tests/Feature/SecurityHeadersMiddlewareTest.php:34` — changing it breaks the test.
- **`TILE_SERVER_IP/PORT/SCHEME` are inert.** `config/map.php:10-14` builds `tile_url`
  from them and nothing consumes it. Changing only `TILE_SERVER_IP` leaves every map on
  OSM with no error. The only lever is `TILE_URL_TEMPLATE`.
- **`/op` CDN and the `Swal` bug need their own issues** (owner decision, 2026-10-28).
  `app.blade.php:401` really is a comment, yet `Swal.fire` is called at `:411` with no
  other provider anywhere in source or `public/build/assets`; the `swal` listener throws
  `ReferenceError` and the calls in `todo.blade.php:251` and
  `tickets/⚡inbox.blade.php:258,314,401` do nothing. Recorded here, not fixed — phase 1
  touches no code.
- **No automated guard yet.** A `tests/Feature/NoExternalRuntimeAssetsTest.php` that
  strips HTML/Blade comments and asserts on `(src|href)=["'](https?:)?//` plus
  `url\(http` with an explicit allowlist (`op/index.php` only) belongs to a separate
  issue (owner decision, 2026-10-28).

## Phase 2

Phase 2 is the self-hosted tile server. It starts **only** after the OSM tile-stack
decision (server address/scheme/port, path template, zoom range, data licence and
attribution text) is closed — tracked as its own issue, not opened as of 2026-10-28.
Nothing in phase 2 runs before that: not the `TILE_URL_TEMPLATE` change, not the
`map-store.js` fallback removal, not the map e2e stub change.

When it does start, the only real lever is `TILE_URL_TEMPLATE` — **not**
`TILE_SERVER_IP/PORT/SCHEME` — and acceptance is "zero requests to
`tile.openstreetmap.org`", not "the map rendered". The preferred topology is a
same-origin proxy (`TILE_URL_TEMPLATE=/tiles/{z}/{x}/{y}.png` with an nginx
`proxy_pass`) so the future `img-src 'self'` CSP, HTTPS pages and HSTS
(`SecurityHeaders.php:28`) stay consistent.
