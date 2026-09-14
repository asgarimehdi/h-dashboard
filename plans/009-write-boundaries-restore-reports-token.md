# Plan 009: Mass-Assignment, Restore, Report Gate, Map Token, Dead Policy

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Models/Hardware.php app/Models/User.php routes/api.php resources/views/livewire/map/map-dashboard.blade.php resources/views/livewire/auth/register.blade.php resources/views/livewire/users/index.blade.php app/Policies/TicketCommentPolicy.php app/Providers/AuthServiceProvider.php resources/views/livewire/tickets/ticket-comments.blade.php resources/views/op/index.php routes/web.php app/Http/Controllers/Api/HardwareAuditController.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P2 (restore silently mints new IDs; password mass-assignable; reports ungated; map token churns per mount)
- **Effort**: M
- **Risk**: MED (User fillable touches ~15 test files; `hashed` cast double-hash trap)
- **Depends on**: 001
- **Category**: security
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Five small security/correctness fixes share one theme (trust boundaries on writes): hardware restore drops the original `id`, `password` sits in `$fillable`, three report endpoints have auth but no authorization, the map dashboard burns a DELETE+INSERT on tokens every mount, and a registered policy enforces nothing while dead code rots beside it.

## Current state (all verified)

- `Hardware.php:25-45` `$fillable` has NO `id`; `HardwareAuditController:200` sets `$restoreData['id'] = $audit->hardware_id` then `Hardware::create($restoreData)` → id silently dropped, new auto-increment; `:208-213` setval assumes restore worked.
- `User.php:24-28`: `$fillable = ['n_code','password','settings']` + `'password' => 'hashed'` cast. Call sites: `register.blade.php:56`, `users/index.blade.php:150` use `Hash::make(...)`.
- `routes/api.php:128-130`: three `/reports/*` routes inside `auth:sanctum`, NO `role_or_permission`. Neighbors use `organization`, `manage_hardware`, `view_assigned_tickets|view_all_tickets`, `calendar`, `view_hr_dashboard`, `map`.
- `map-dashboard.blade.php:43-45` (verified): delete + `createToken('map-dashboard')` every `mount()`; used at `:116` (server `Http::withToken`) and `:263` (JS `apiToken`).
- `TicketCommentPolicy` mapped in `AuthServiceProvider:16-18`, zero `authorize()/can()` call sites for comments; component `ticket-comments.blade.php:68-171` does direct create/update/delete.
- Dead: `GisController::invalidateCache()` (zero callers), org-chart `collectFirstNLevels()` (superseded by `expandFirstNLevels()`), `auth/register.blade.php` (route commented `web.php:18`), vendored `resources/views/op/index.php:1821` OPcache GUI. NOT dead: 12 Hardware scopes (used by API controller), `HardwareUpdated` event (dispatched `HardwareController:185,221,234,310` — but double-bumps with model boot; plan 010 owns the event decision, do NOT delete here).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Call sites | `grep -rn 'User::create\|User::fill' --include='*.php' app/ resources/views/ tests/ \| grep -i pass` | list to update |
| Tests | `composer test` | pass |

## Scope

**In scope**: files listed in drift check + test files with `User::create`.
**Out of scope**: event/listener removal (plan 010), LIKE-scope dedup (plan 007 — wait, no: DEBT-09 decision needed. Keep: do NOT build `PersianSearch` here; plan 007 owns export JOINs. If trivial, one helper + two controllers max, else defer with note), map UI sprawl (defer — LOW).

## Steps

### Step 1: Hardware `id` + report gate + map token (quick wins)

Hardware `$fillable` += `'id'` (comment: restore-only). Reports: wrap `:128-130` in `role_or_permission` — check `PermissionSeeder` for existing `report` permission; if present use it, else reuse the closest existing gate covering all three datasets (do NOT invent a new permission without seeder + role assignments + tests). Map: session-cached token (`session('map_token_'.$user->id)`, create-once, reuse; revoke on logout handler).
**Verify**: restore returns original id (new test); no-permission token → 403 on reports; single token row across remounts.

### Step 2: `password` out of fillable (double-hash trap!)

Remove `'password'` from `$fillable`. Update call sites to `User::create(['n_code'=>...])` then `$user->password = 'plaintext'; $user->save();` — RAW plaintext, because the `'hashed'` cast hashes on set; `Hash::make()` + cast = double-hash = locked-out users. Update all ~15 test files the same way.
**Verify**: `Hash::check('secret', $user->fresh()->password)` true; login works; full suite green.

### Step 3: Policy — enforce or delete; dead code out

Either `$this->authorize()` in component/controller + real policy methods, or delete policy + mapping. Delete `invalidateCache()`, `collectFirstNLevels()`, `register.blade.php` (or wire route — delete is safer), move/gate OPcache GUI (behind existing dev-only protection or delete).
**Verify**: comment authz tested both ways; grep confirms deletions; suite green.

## Test plan

- New: restore-id, report-403, token-reuse, password-hash, policy allow/deny tests.

## Done criteria

- [ ] `id` in Hardware fillable + restore-id test
- [ ] `password` NOT in User fillable; all call sites explicit; no double-hash
- [ ] Report routes gated + tested
- [ ] Token created once per session + logout cleanup
- [ ] Policy enforced or removed; dead code deleted
- [ ] Suite green; scope clean

## STOP conditions

- No fitting existing report permission AND new-permission path touches roles broadly → stop, report options.
- `Hash::make` + `hashed` cast interaction differs from described → stop, verify with tinker before mass-editing tests.
- Verification fails twice → stop, report.
