# API, Auth, Headers & Notifications

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

## Authentication

**Laravel Sanctum** with two modes:

| Mode | Routes | Auth Method |
|---|---|---|
| **Web (Session)** | Livewire UI pages | Cookie-based session via `web` guard |
| **API (Token)** | `/api/*` routes | Bearer token via `sanctum` guard |

- Livewire components expect session-based auth. **API tokens are NOT accepted** for Livewire pages.
- Login form at `/login`. API login: `POST /api/login` with `n_code` + `password` (throttled 5/min per account **and** per IP; the per-account key is the submitted `n_code`, so rotating IPs does not reset it — #1666).

### Token lifecycle & pruning (#1820)

`POST /api/login` mints a fresh `flutter-app` token on every call and does **not** revoke the caller's previous tokens (a single-live-token policy is an open product decision; revoking on role/permission change is #1823). The 24-hour lifetime is enforced by the **guard** off `created_at`, not by `expires_at`: `createToken()` is called without the third argument, so every token this app mints has `expires_at = NULL`.

- `sanctum:prune-expired` runs daily at 05:30 (between `imports:prune-sheets` 05:15 and `reports:generate-daily` 06:00) with Sanctum's default 24-hour grace, so a row is deleted once `expires_at` is more than 24h past **or** `created_at` is more than `expiration + 24h` old — 48 hours with the default `SANCTUM_TOKEN_EXPIRATION=1440`.
- Password change deletes **all** of the user's tokens (#678/#1030); logout deletes only the caller's current token (#1071). Neither path is a substitute for the prune.
- `SANCTUM_TOKEN_EXPIRATION=0` disables guard expiry, and the command then skips the config-based branch with a warning (the `expires_at` branch still runs).

### Cookie-authenticated `/api/*` calls from the web UI (#1399, #1532)

The GIS dashboard and the Zabbix pages call `/api/gis/*` and `/api/zabbix/*` with the **session cookie and no `Authorization` header**. Sanctum only runs the stateful pipeline for such a request when its `Referer`/`Origin` host is in `config('sanctum.stateful')`; otherwise every call answers `401 {"message":"Unauthenticated."}`.

- The list is **merged, never env-only**: the incoming request host (`Sanctum::$currentRequestHostPlaceholder`, resolved per request by `fromFrontend()`) and the `APP_URL` host are always stateful, and `SANCTUM_STATEFUL_DOMAINS` only allowlists extra origins. A deployment served from a host/port outside the env list (sad1:8000, a LAN IP, a second port) therefore works without an env edit — `php artisan config:clear` after deploy.
- The placeholder is safe: it only matches a `Referer`/`Origin` equal to the request's own host, which a cross-site page cannot forge; a forged `Host` gets no victim cookie, and writes still need CSRF + `SameSite=lax`.
- The client side is one shared helper: `resources/js/api-fetch.js` `apiFetch()` adds `Accept: application/json` + `credentials: 'include'` and **latches** 401/403 for the whole page. The latch is terminal — retrying a cookie call cannot make Sanctum trust the host, and a 403 does not heal — so map pans and Zabbix timers stop instead of re-firing. Render `<x-api-auth-banner />` **once per page**; never a per-layer or per-chart message.
- A 401 here is a config/deployment condition, not a transient failure: sign in again or fix `config/sanctum.php` + `config:clear`. `tests/e2e/maps/auth-failure.spec.ts` pins the client half (one request per layer, one banner, zero retries).

### API Token Abilities (issue #690)

Every `/api/*` route group additionally requires a **token ability** — `auth:sanctum` alone is not enough (403 otherwise) — **except three intentionally exempt routes**: `POST /api/login` (public; it mints the token), `GET /api/user` and `POST /api/logout` (self-scoped; they act on the caller, not on a resource — logout by #1071, following the pre-existing `/api/user` precedent).

Sanctum middleware semantics: **`ability:a,b` = ANY one of them**, **`abilities:a,b` = ALL of them** (`CheckForAnyAbility` vs `CheckAbilities`).

| Route group | Read (GET) | Write (POST/PUT/DELETE) |
|---|---|---|
| `/api/login`, `/api/user`, `/api/logout` | **none** — `POST /api/login` is public (no `auth:sanctum`); `GET /api/user` + `POST /api/logout` are `auth:sanctum` only (self-scoped; logout by #1071) | — |
| `/api/units` | `ability:units:read` + `role_or_permission:organization` | `abilities:units:write` + `role_or_permission:organization` |
| `/api/zabbix/traffic`, `/api/zabbix/multi-latest` | `ability:traffic:read` + `role_or_permission:map\|bw`; item IDs must also be registered on an active `zabbix_devices` row (#1621, 422 otherwise) | — |
| `/api/hardware/*` | `ability:hardware:read` + `role_or_permission:manage_hardware` | `abilities:hardware:write` + `role_or_permission:manage_hardware` |
| `/api/tickets*` (+ comments) | `ability:tickets:read` + `role_or_permission:view_assigned_tickets\|view_all_tickets` | `abilities:tickets:write` + per-route `permission:create_ticket` / `permission:manage_unit_tickets` — **`tickets:read` is NOT required for writes** (#895); reads and writes are two independent groups |
| `/api/reports/*` | `ability:reports:read` + `role_or_permission:manage_personnel` | — |
| `/api/persons/*` | `ability:persons:read` + `role_or_permission:kargozini\|manage_personnel` | `abilities:persons:write` + `role_or_permission:manage_personnel` |
| `/api/todos*` | `ability:todos:read,todos:write` (any of the two) + `role_or_permission:calendar` | `abilities:todos:write` (nested inside the outer group, ALL) + `role_or_permission:calendar` (#837) |
| `/api/hr/*` | `ability:hr:read` + `role_or_permission:view_hr_dashboard`; row-level PII in `/personnel*` requires `kargozini\|manage_personnel` | — (no write routes; the group is GET-only) |
| `/api/notifications*` | `ability:notifications:read` | `abilities:notifications:write` (POST `{id}/read` and `read-all`, in their own group since #1002) |
| `/api/gis/units`, `/api/gis/stats`, `/api/gis/clusters` | `ability:gis:read` + `role_or_permission:map\|bw` | — |
| `/api/gis/hardware` (#1743) | same `gis` group **plus** `role_or_permission:manage_hardware` (nested = AND — `gis:read` + `map\|bw` still apply) | — |
| `/api/gis/tickets` (#1743) | same `gis` group **plus** `role_or_permission:view_assigned_tickets\|view_all_tickets` (nested = AND; **no extra assignment filter** — mirrors `/api/tickets`) | — |
| `/api/gis/diseases`, `/api/gis/disease-stats`, `/api/gis/disease-map` (#1245) | same `gis` group: `ability:gis:read` + `role_or_permission:map\|bw` | — (GET-only; no `manage_diseases`). Payloads are **aggregate-only and PII-free** — counts, rates and organizational names, never a patient identifier, name or dynamic field value. |

**Adding a new `/api/gis/*` route is an explicit decision**: geographic aggregate (group gate only) or data route (its own data permission too) — the group is no longer a uniform gate (#1743). The map dashboard hides the hardware/ticket layer buttons server-side for the same reason: `apiFetch()` treats 403 as a terminal auth failure, so an unauthorized fetch would stop every other layer from loading (#1532).

- Mint a token with abilities: `$user->createToken('name', ['hardware:read'])->plainTextToken`.
- Groups that also carry `role_or_permission:*` need **both** — token ability and Spatie permission — or the request is 403.
- **Read and write ticket groups are independent (#895).** The reads sit in `ability:tickets:read` + `role_or_permission:view_assigned_tickets|view_all_tickets`; the writes sit in their **own** `abilities:tickets:write` group plus a per-route `permission:*`, and **no write route requires `tickets:read`**. They used to be nested inside the read group, and because nested middleware is AND, a token that satisfied `tickets:write` but not `tickets:read` — which is exactly what `getApiTokenAbilities()` mints for the seeded `user` role, holding `create_ticket` alone — was rejected with `Invalid ability provided.` on every write. `abilities:tickets:write` + `permission:*` per route is load-bearing and must stay on every write.
- **Notification writes are split from reads (#1002, superseding the #837 arrangement).** `POST /api/notifications/{id}/read` and `POST /api/notifications/read-all` sit in their own group behind `abilities:notifications:write`, and `getApiTokenAbilities()` mints **both** `notifications:read` and `notifications:write`. Tokens minted before the split carry only `read` and get a 403 on the POSTs until they are re-minted — that is expected, not a bug to route around.
- Tokens are **scoped and revoked on password change** (#678) on **both** password-change paths: the self-service form (`resources/views/livewire/auth/changepassword.blade.php`) and an admin reset through `users.index`'s `updateUser()` (#1030) each `delete()` **all** of the account's Sanctum tokens (today the Flutter app's; legacy `map-dashboard` tokens from before #1428 die with them), so the two paths stay in lockstep. The admin path is guarded on an actually-changed password: a profile/role edit that leaves the password untouched does not revoke by itself. Separately (#1823), an edit that **changes the holder's effective API ability set** — a role/permission change through `users.index`, a role permission change through `roles.index`, or a role deletion — deletes that holder's tokens (on any change, grant or revoke, so token abilities always equal the holder's current abilities); a privilege-neutral edit (no-op, rename-only, unit-only) revokes nothing. API tests use **real Bearer tokens** with explicit abilities, not bare `Sanctum::actingAs()` — pattern in `tests/Feature/ApiAbilityTest.php`.


**Hardware routes require full auth:** `auth` + `role_or_permission:manage_hardware`. A guest-bypassing middleware alias used to sit here and was removed in #1287 — do not reintroduce a middleware that skips `auth()->check()` for guests; its test was pinning 200-for-guests.

> **Gotcha:** `test_hardware_page_loads_without_auth` asserts **302 → /login** for guests — a security decision. Do NOT "fix" it back to 200 — that reopens the data leak.

**Unit Context Middleware:** `ValidateUnitContext` ensures `session('current_unit_id')` is set before entering unit-scoped sections.

### Patient registry write path (#1300)

`App\Services\PatientService` is the patient registry's **only write path** — web/Livewire only; **no API route or token ability is added for patients (ADR 0002: no patient write API)**. Input is Jalali (`birth_date_jalali`); `create()` normalizes (digits + trim) *then* re-applies its `validationRules()` on the normalized copy, inserts only the five validated keys, and wraps insert + `ActivityLogService::created` audit in one transaction — a failed audit rolls the patient back. No unit scope (`patients` has no unit column). A PostgreSQL `23505` becomes a `ValidationException` under the `duplicate` key with a PII-free message; callers keep the friendly path (`duplicateMessage` + `duplicatePatientId` via lookup) in `disease/patients` and map errors onto `newPatient.<key>` in the case form.

### Security Headers

`App\Http\Middleware\SecurityHeaders` is appended to the **`web` middleware group** in
`bootstrap/app.php` (after the default stack; only `RequestId` sits inside it). Coverage is
therefore a property of that group and of the middleware's position in it — four distinct layers:

| What the client requested | Layer | Headers present |
|---|---|---|
| A web response that enters the group — `GET /login` 200, and controller errors such as `abort(404)` or a thrown 500 (rendered inside the pipeline, so the rendered response unwinds back out through the middleware) | app — `SecurityHeaders` | **all six**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy` (enforcing) or `Content-Security-Policy-Report-Only` (default), `Reporting-Endpoints: csp-endpoint="/csp-report"`, `Strict-Transport-Security: max-age=31536000; includeSubDomains` |
| A response produced **before** the middleware runs — the auth `302 → /login`, a CSRF `419`, a router-level `404`/`405`, and `GET /up` 200 | app — `web` group ordering; `/up` is outside the group | **none of the six** |
| `/api/*` | app — `api` group, where `SecurityHeaders` is **not** registered | `X-Request-Id` only (#1068). Deliberate exception: `GET /api/tickets/{ticket}/attachments/{attachment}` sets `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY` itself (`TicketAttachmentController::DOWNLOAD_HEADERS`) — the inline jpg/jpeg/png/pdf attachment is the only browser-rendered document on the API, so XFO is the one header that does real work there |
| Static assets — `/build/assets`, and the regex locations `/js`, `/css`, `/icons`, `/fonts` | **nginx** | `X-Content-Type-Options: nosniff` via `add_header` in `install-guid.md` (§ static assets, ~lines 262–291); repeated per location because `add_header` does not inherit, and pinned by `InstallGuideNginxConfigTest`. **No `X-Frame-Options`** (#1571): assets are never framing targets, and nginx `add_header` appends rather than replaces, so a value here would ship beside the middleware's `DENY` as a duplicate — the single `X-Frame-Options` a browser sees on web responses comes from the app |

The app-layer CSP is single-sourced in `App\Support\CspPolicy::for($nonce)` with a per-request
nonce: `'unsafe-inline'` is gone from `script-src`/`style-src`; `'unsafe-eval'` (phase 2 PR 2,
#1352 — Livewire serves its CSP-safe build via `config('livewire.csp_safe')`) and
`style-src-attr 'unsafe-inline'` (phase 2 PR 1, #1352, after the `style=` migration) are gone;
`connect-src` carries the OSRM/Nominatim origins from `config/map.php`; the mode switches via
`config('csp.enforcing')` / `CSP_ENFORCING` (#1281, phase 1).

> **Do not add `X-XSS-Protection`** — replaced by CSP. The old header is removed.

> **The old "all responses" claim was wrong twice over:** the `web` group does not cover
> responses generated before `SecurityHeaders` runs, and `/api/*` never runs it at all. Layer
> nginx on top and the header set also differs per location.

### CSP violation reporting (issue #742)

`POST /csp-report` (name `csp.report`) — **public, no auth**, `throttle:60,1`, handled by `App\Http\Controllers\CspReportController` (extracted from the old `routes/web.php` closure in #1767).

- The CSP header carries `report-to csp-endpoint` and the **deprecated `report-uri` directive is gone** — modern Chrome never acted on it, so it was a lie.
- Route is **CSRF-exempt** via `$middleware->validateCsrfTokens(except: ['csp-report'])` in `bootstrap/app.php` — a browser report carries no token.
- The Reporting API POSTs `application/reports+json`: a **JSON array** of `{"type":"csp-violation","url":…,"body":{…}}` envelopes (plus `age`/`user_agent`). The legacy `application/csp-report` `{"csp-report":{…}}` shape is also accepted for compatibility; no `report-uri` is emitted today. Shape decides — Content-Type is not a gate — and a single Reporting API object (no array) is rejected.
- `CspReportController` validates shape (allowlisted fields per shape, `type === 'csp-violation'`), caps size (16 KiB body, 20 reports/fields, 4 KiB per string), sanitises control characters (Laravel's `LineFormatter` unescapes `\n` back to real newlines — #1767), logs one `Log::warning('csp-report', …)`, and always returns **204** (junk is dropped without a log).
- **The same-app destination is temporary.** The decision on #742: the final target must be a **separate-domain** endpoint once that infrastructure exists — follow-up issue material.

> Gotcha: the endpoint is unauthenticated by design, so a well-formed forged report is still possible at the throttle rate. The fix bounds shape, size, field count and log injection; it does not authenticate the reporter.

### Web download throttling (issue #1712)

- Web downloads (`/hardware/export`, `/units/export`, `/kargozini/persons/export`, `/tools/backup/full|table/{table}`) — `throttle:web-downloads`, 10/min per user (#1712). One shared budget across all five; keyed by user id (IP for a user-less request).

### CORS Hardening

`config/cors.php` changes:
- `allowed_origins_patterns` now **empty array in production** (was always localhost/127.0.0.1)
- `max_age` increased from 0 to 86400 (reduces preflight requests)

## Notification API (Flutter)

REST API endpoints for the Flutter mobile app (`/api/notifications/*`):

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/notifications` | GET | Paginated notification list (20/page) |
| `/api/notifications/unread-count` | GET | Count of unread notifications |
| `/api/notifications/{id}/read` | POST | Mark single notification as read |
| `/api/notifications/read-all` | POST | Mark all notifications as read |

Controller: `App\Http\Controllers\Api\NotificationController`. Requires Sanctum auth.

> **Sanctum token expiration** is 24 hours (1440 min), configurable via the `SANCTUM_TOKEN_EXPIRATION` env var. Expiry is applied by the guard off `created_at`, and expired rows are pruned daily — see [Token lifecycle & pruning](#token-lifecycle--pruning-1820).
