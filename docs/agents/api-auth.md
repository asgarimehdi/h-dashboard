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
- Login form at `/login`. API login: `POST /api/login` with `n_code` + `password` (throttled 5/min).

### API Token Abilities (issue #690)

Every `/api/*` route group additionally requires a **token ability** — `auth:sanctum` alone is not enough (403 otherwise) — **except three intentionally exempt routes**: `POST /api/login` (public; it mints the token), `GET /api/user` and `POST /api/logout` (self-scoped; they act on the caller, not on a resource — logout by #1071, following the pre-existing `/api/user` precedent). A fourth exception is a decision, not an implementation detail: record it here and in `AGENTS.md`.

Sanctum middleware semantics: **`ability:a,b` = ANY one of them**, **`abilities:a,b` = ALL of them** (`CheckForAnyAbility` vs `CheckAbilities`).

| Route group | Read (GET) | Write (POST/PUT/DELETE) |
|---|---|---|
| `/api/login`, `/api/user`, `/api/logout` | **none** — `POST /api/login` is public (no `auth:sanctum`); `GET /api/user` + `POST /api/logout` are `auth:sanctum` only (self-scoped; logout by #1071) | — |
| `/api/units` | `ability:units:read` + `role_or_permission:organization` | `abilities:units:write` + `role_or_permission:organization` |
| `/api/zabbix/traffic`, `/api/zabbix/multi-latest` | `ability:traffic:read` + `role_or_permission:map\|bw` | — |
| `/api/hardware/*` | `ability:hardware:read` + `role_or_permission:manage_hardware` | `abilities:hardware:write` + `role_or_permission:manage_hardware` |
| `/api/tickets*` (+ comments) | `ability:tickets:read` + `role_or_permission:view_assigned_tickets\|view_all_tickets` | `abilities:tickets:write` + per-route `permission:create_ticket` / `permission:manage_unit_tickets` — **`tickets:read` is NOT required for writes** (#895); reads and writes are two independent groups |
| `/api/reports/*` | `ability:reports:read` + `role_or_permission:manage_personnel` | — |
| `/api/persons/*` | `ability:persons:read` + `role_or_permission:kargozini\|manage_personnel` | `abilities:persons:write` + `role_or_permission:manage_personnel` |
| `/api/todos*` | `ability:todos:read,todos:write` (any of the two) + `role_or_permission:calendar` | `abilities:todos:write` (nested inside the outer group, ALL) + `role_or_permission:calendar` (#837) |
| `/api/hr/*` | `ability:hr:read` + `role_or_permission:view_hr_dashboard` | — (no write routes; the group is GET-only) |
| `/api/notifications*` | `ability:notifications:read` | `abilities:notifications:write` (POST `{id}/read` and `read-all`, in their own group since #1002) |
| `/api/gis*` | `ability:gis:read` + `role_or_permission:map\|bw` | — |
| `/api/gis/diseases`, `/api/gis/disease-stats`, `/api/gis/disease-map` (#1245) | same `gis` group: `ability:gis:read` + `role_or_permission:map\|bw` | — (GET-only; no `manage_diseases`). Payloads are **aggregate-only and PII-free** — counts, rates and organizational names, never a patient identifier, name or dynamic field value. |

- Mint a token with abilities: `$user->createToken('name', ['hardware:read'])->plainTextToken`.
- Groups that also carry `role_or_permission:*` need **both** — token ability and Spatie permission — or the request is 403.
- **Read and write ticket groups are independent (#895).** The reads sit in `ability:tickets:read` + `role_or_permission:view_assigned_tickets|view_all_tickets`; the writes sit in their **own** `abilities:tickets:write` group plus a per-route `permission:*`, and **no write route requires `tickets:read`**. They used to be nested inside the read group, and because nested middleware is AND, a token that satisfied `tickets:write` but not `tickets:read` — which is exactly what `getApiTokenAbilities()` mints for the seeded `user` role, holding `create_ticket` alone — was rejected with `Invalid ability provided.` on every write. `abilities:tickets:write` + `permission:*` per route is load-bearing and must stay on every write.
- **Notification writes are split from reads (#1002, superseding the #837 arrangement).** `POST /api/notifications/{id}/read` and `POST /api/notifications/read-all` sit in their own group behind `abilities:notifications:write`, and `getApiTokenAbilities()` mints **both** `notifications:read` and `notifications:write`. Tokens minted before the split carry only `read` and get a 403 on the POSTs until they are re-minted — that is expected, not a bug to route around.
- Tokens are **scoped and revoked on password change** (#678) on **both** password-change paths: the self-service form (`resources/views/livewire/auth/changepassword.blade.php`) and an admin reset through `users.index`'s `updateUser()` (#1030) each `delete()` **all** of the account's Sanctum tokens — including `map-dashboard`, not just `flutter-app`, so the two paths stay in lockstep. The admin path is guarded on an actually-changed password: a profile/role edit that leaves the password untouched keeps every token alive. API tests use **real Bearer tokens** with explicit abilities, not bare `Sanctum::actingAs()` — pattern in `tests/Feature/ApiAbilityTest.php`.


**Hardware routes require full auth:** `auth` + `role_or_permission:manage_hardware`. A guest-bypassing middleware alias used to sit here and was removed in #1287 — do not reintroduce a middleware that skips `auth()->check()` for guests; its test was pinning 200-for-guests.

> **Gotcha:** `test_hardware_page_loads_without_auth` asserts **302 → /login** for guests — a security decision. Do NOT "fix" it back to 200 — that reopens the data leak.

**Unit Context Middleware:** `ValidateUnitContext` ensures `session('current_unit_id')` is set before entering unit-scoped sections.

### Security Headers

`SecurityHeaders` middleware sets on every response:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy-Report-Only` — CSP in report-only mode (validate 1-2 weeks before enforcing)
- `Reporting-Endpoints: csp-endpoint="/csp-report"` — the Reporting API destination
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` (HSTS)

> **Do not add `X-XSS-Protection`** — replaced by CSP. The old header is removed.

### CSP violation reporting (issue #742)

`POST /csp-report` (name `csp.report`) — **public, no auth**, `throttle:60,1`, named in `routes/web.php`.

- The CSP header carries `report-to csp-endpoint` and the **deprecated `report-uri` directive is gone** — modern Chrome never acted on it, so it was a lie.
- Route is **CSRF-exempt** via `$middleware->validateCsrfTokens(except: ['csp-report'])` in `bootstrap/app.php` — a browser report carries no token.
- Body is read with `$request->json()->all()` (Chrome sends `application/csp-report`, others `application/json`); a garbage body decodes to `[]` instead of throwing. Logs `Log::warning('csp-report', …)` and returns **204**.
- **The same-app destination is temporary.** The decision on #742: the final target must be a **separate-domain** endpoint once that infrastructure exists — follow-up issue material.

> Gotcha: it logs at `warning` with a **user-controlled body**. A large/garbage body is rate-limited but not size-capped, so do not treat `zabbix_sync_logs`-style assumptions about log volume as safe here.

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

> **Sanctum token expiration** reduced from 7 days (10080 min) to 24 hours (1440 min). Configurable via `SANCTUM_TOKEN_EXPIRATION` env var.
