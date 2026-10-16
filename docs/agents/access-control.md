# Access Control & Permissions

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

## Access Control

Uses **Spatie Permission** package:

- `HasOrganizationalScope` trait on models for **opt-in** unit-based filtering via `->accessible($column)`. It is a single local scope — **there is no global scope**, so a query is unscoped until it calls it. Its `whereIn` is unconditional, so an empty scope compiles to `0 = 1` (fail-closed).
- **Never special-case an empty scope as "no restriction."** There are **three spellings**, and a sweep that only greps one of them will miss the other two (issue #839):

  | Spelling | Why it fails open |
  |---|---|
  | `->when($accessibleIds, fn ($q) => …)` | `Conditionable::when()` runs the callback only for a **truthy** value, so `[]` drops the predicate — the #819 leak in five `/reports/*` components |
  | `if (! empty($accessibleIds)) { $q->whereIn(…); }` | the predicate is simply never added — #839, all **8** sites in `PersonImport` / `HardwareImport` |
  | `if (! empty($accessibleIds) && ! in_array($id, $accessibleIds)) { reject }` | the **first conjunct is false**, so `&&` short-circuits and the row is **ACCEPTED** — the same bug with extra steps, and the most dangerous spelling because it *looks* like a scope check |

  `AccessibleUnitIds()` is legitimately `[]` for an account with no `user_units` row and no `person.u_id`, and such a user is deliberately allowed through `ValidateUnitContext`. **`[]` means "in scope of nothing", never "unrestricted".** Use a plain `->whereIn($column, $accessibleIds)` (or `->accessible($column)`) instead — an empty array compiles to `0 = 1`. The two-`when` form in `UnitsExportController` / `PersonsExportController` (`when($accessibleIds === [], whereRaw('1 = 0'))`) is also correct — but the unconditional one is one condition instead of two that must both stay right.
- **The sweep to run after any scope change** (covers all three spellings at once):
  ```bash
  grep -rnE '(when\(\s*\$[a-zA-Z]*[Ii]ds|! *empty\(\$[a-zA-Z]*[Ii]ds\))' app/ resources/views/
  ```
  The blind sweep above cannot see the third leak shape: a scoping
  precondition that is simply **absent** — no `when`, no empty guard, no
  predicate at all, just an unscoped query. The ticket selectors in
  `tickets/⚡inbox.blade.php` and `tickets/⚡create.blade.php` shipped
  exactly this shape (#980's correction) and their fix was a plain
  scoping `whereIn` added where nothing guarded the picker before.
  Grep for suspicious models too, not only for fail-open spellings:
  ```bash
  grep -rnE 'Unit::query\(\)|Person::query\(\)|Ticket::query\(\)' resources/views/ app/
  ```
  and read each hit: a unit/person picker that goes to the DB with no
  `whereIn(... unitIds())` at all is the same leak this section is about,
  just silent — no fail-open spelling to catch. Read **every** hit; the
  two sweeps together catch the `when`/`empty` shapes **and** absence.
- Users see only their own unit's data (plus sub-units via recursive CTE)
- **`users.index` is unit-scoped (issue #849) — `manage_users` is NOT a global grant.** The whole file avoids every fail-open spelling: no `when($ids, …)`, no `! empty($ids)` — each predicate is an unconditional `whereIn` / `in_array`, so an empty scope is `0 = 1`, never "no filter".
  - `users()` filters with the **disjunction** `person.u_id ∈ scope` **OR** a `user_units` row ∈ scope. Do not copy `activity-log`'s `whereHas('person', …)` verbatim here: alone it hides accounts with no linked person (admin accounts).
  - `mount()` builds `allUnits` / `allUnitsTree` from `Unit::whereIn('id', $accessibleIds)` — the picker is the write-target list, not a directory. The person search is scoped by its own AND-group `whereIn('u_id', …)`, the same predicate as `->accessible('u_id')` (written out because a local-scope call is not resolvable without larastan), so the `LIKE … OR n_code LIKE` group cannot bypass it. `selectPerson()` `abort(403)`s on an out-of-scope `n_code` — `n_code` is the account key.
  - The scope check lives in the **mutators** (`createUser`, `updateUser`, `delete`, `restore`) *and* in `edit()`. An `edit()`-only check is bypassable: `editing_user_id` is public, client-settable Livewire state with no `#[Locked]`. `updateUser()` checks the loaded target, the target's **current** units (because `units()->sync()` is a full replace, not an append) and every submitted `unit_id` — same rule as `kargozini.person` validating both the stored and the submitted `u_id`. Violations are `abort(403)`, not a toast.
  - **Closed decision (#849):** `manage_users` may change a user's units *inside its own scope* — the real gap was *which* units, not *whether*, and scoping answers it. No new permission was added (a permission missing from a deployment's DB silently 403s every non-admin). **`createUser()` now requires `role_ids` (`required|array|min:1`, identical to `updateUser()`'s rule)** — role-less account creation is gone; do not relax it back to `nullable|array`.
  - Pinned by the `#849` block in `tests/Feature/UsersManagementTest.php`. That block grants the attacker `manage_roles` **on purpose**: otherwise the permission gate, not the unit scope, would be what answers with a 403 and the assertion would prove nothing.
- Permission `manage_hardware` required for hardware CRUD
- Roles: admin, operator, viewer

**AccessService** provides `accessibleUnitIds()` → unit IDs the current user can access (unit + descendants via recursive CTE). Results are cached and version-invalidated. It also provides `allUnitIds()` → every unit id, with **no actor and no session**, deliberately uncached (#836) — use it in scheduler/worker paths, where `accessibleUnitIds()` is always `[]`.

**Key permissions:** `manage_users`, `organization`, `kargozini`, `map`, `manage_zabbix`, `calendar`, `view_all_tickets`, `create_ticket`, `view_assigned_tickets`, `manage_roles`, `op-cache`, `manage_hardware`, `bw`, `view_hr_dashboard`, `manage_personnel`, `manage_unit_tickets`, `manage_org_chart`.

### Sidebar ↔ route permission contract

Every session starts **in `h-dashboard`** (the Hermes `terminal.cwd` default) and uses **CodeGraph first** for any
code question, plus the **superpowers** skills for process (`brainstorming`, `systematic-debugging`,
`test-driven-development`, `verification-before-completion`).

A sidebar item in `resources/views/components/layouts/app.blade.php` and the route it points at in
`routes/web.php` **must carry the same permission(s)**. A link the user cannot open is a 403 they were
invited to click; a hidden link for a permission the route accepts is a lost feature. Pinned by
`tests/Feature/SidebarPermissionsTest.php`, which renders the sidebar per permission and asserts each
link's visibility in both directions, plus a real `GET` per link.

- Groups: **ابزارهای مدیریتی** (`map`|`bw` → `/it/networks` + `/it/wireless`; `manage_zabbix` →
  `/it/zabbix-devices`; `op-cache` → `/op`, with `@production` so the link never renders where the route
  does not exist), **ابزار مدیریتی** (`manage_users` → `/tools`), **سخت افزار** (`manage_hardware` →
  `/hardware` + `/maintenance`), **گزارش ها** (`manage_personnel`, plus `manage_users` for `/activity-log`).
- `/it/networks` and `/it/wireless` live in their **own** `role_or_permission:map|bw` group — **not**
  nested inside the `map` group. Nesting re-adds `map` as an extra requirement and 403s a `bw`-only user
  (there is a regression test for exactly this). Nested `middleware(['a', 'b'])` means **AND**, not OR.
- `role_or_permission` syntax is `a|b` = ANY. It is **not** the `ability:a,b` / `abilities:a,b` comma form
  from Sanctum, and it resolves through `canAny()`, so a permission name is never treated as a role.
- `/reports/*` is gated by `manage_personnel`, deliberately **not** a new permission: a permission that
  exists in the seeder but not on a deployment's DB 403s every non-admin, because Spatie's
  `hasAnyPermission()` answers `false` for an unknown name instead of throwing. `RoleSeeder` grants
  `manage_personnel` to `unit_manager` so that role keeps the report access it had before the gate existed,
  and `PermissionSeeder` `givePermissionTo`s it for admin.
- Personnel (issue #775 decision): **read** (list `/kargozini/persons` + export) is the union
  `role_or_permission:kargozini|manage_personnel` — `kargozini` is the lookup-table permission, NOT the
  personnel gate. **Write** (import `/kargozini/persons/import`) requires `manage_personnel` alone,
  matching the API (`abilities:persons:write` + `manage_personnel`). The lookup tables
  (estekhdams/tahsils/semats/radifs) stay `kargozini`-only.
- The personnel **component** re-checks the write permission itself (issue #805). Because its route
  gate is the read union, `kargozini.person` calls `$this->authorize('manage_personnel')` in
  `startCreate()`, `savePerson()`, `editPerson()` and `delete()`, and the create/edit/delete controls
  are wrapped in `@can('manage_personnel')`. Rule of thumb: **a page on a read-union gate that also
  writes must authorize inside the component, not only in the route.** The write path also
  `syncWithoutDetaching()`s `user_units` (role `staff`, primary unit) for a linked user — it rewrites
  the actor's reachable units, so an ungated write there is a privilege escalation, not data entry.
  The update branch re-validates the **submitted** `u_id` against `accessibleUnitIds()`, not just the
  stored one, or a record can be moved into a unit the actor never had read access to. Pinned by
  `tests/Feature/Kargozini/PersonLivewireTest.php` (the `#805` block).
- Todo rows (issue #838 decision): **null-unit todo = creator-owned; UI and API enforce the same
  rule.** A `todos` row with `unit_id = null` belongs to its creator (`user_id`) — it is listed
  (`getEvents()`), readable and mutable only by that user, on both surfaces. The Livewire
  component (`todo.todo`: `getEvents()`, `isTodoAccessible()`, `save()` target-row check,
  `#[Locked]` on `$editingId`) and `Api\TodoController` (`show`/`update`/`destroy`/
  `toggleComplete`) apply the identical predicate, so a row the UI edits is never a row the API
  403s and vice versa. Pinned by `tests/Feature/TodoLivewireTest.php` (the `#838` block) and the
  creator/non-creator parity tests in `tests/Feature/TodoApiTest.php`. The same grouped predicate
  also governs the list surfaces (#917): `Api\TodoController::index()` (written out in the
  controller; the shared form is the `Todo::accessibleTo()` scope that `isTodoAccessible()`
  delegates to), the profile counters and `getUserTodosProperty()`, the global search todos
  branch, and the `tickets.create` picker — the owner constraint sits inside the grouped
  predicate, so UI and API agree row-for-row and cannot drift again.
- `resources/views/components/help/content/permissions.blade.php` used to list permissions that never
  existed (`view_hardware`, `view_tickets`, `create_tickets`, `assign_tickets`, `manage_units`,
  `view_units`, `manage_permissions`, `view_reports`, …). Do not re-add them; keep that page in sync with
  `PermissionSeeder`.

## Gotchas (transplanted from the former `## Gotchas Quick Reference`)

| Gotcha | Details |
|---|---|
| `map-no-boundary`'s early return is load-bearing | Its `when($accessibleIds, …)` at :36 is unreachable because :28 returns `collect()` for `[]` — keep that guard. Beyond the query it also keeps an empty result out of `Cache::remember('report:no_boundary:'.md5(implode(',',$accessibleIds)))`, which for `[]` is `md5('')` — ONE shared cache slot for every empty-scope user |
| Nested route middleware is **AND**, not OR | `Route::middleware(['a','b'])->group(...)` — and nesting a route inside another permission group — requires **both**. To accept either, use ONE `role_or_permission:a|b` in a group of its own. This bit `/it/networks` + `/it/wireless`: putting them inside the `role_or_permission:map` group re-added `map` and 403'd a `bw`-only user. `php artisan route:list --path=X -v` prints every resolved middleware — read it instead of guessing |
| Vendor routes registered by a package must be stripped on `booted`, and **from inside a booted hook** | The maryUI `POST /mary/upload` route (#951) is registered by `MaryServiceProvider::boot()` via `loadRoutesFrom()` — no app-side toggle removes it, and it wrote an unvalidated file to a request-chosen disk and folder behind nothing but `auth`. `App\Support\VendorRouteGuard::strip()` removes it from `AppServiceProvider::boot()`. Two non-obvious parts: (1) `route:cache` swaps the router's collection for a `CompiledRouteCollection` whose `match()` reads a flat pre-built index and whose Route objects are rebuilt from `$attributes` on every call — so **mutating a Route does nothing**; the guard rebuilds and recompiles the collection instead (~4 ms, once per process, only while a pre-fix cache is on disk). (2) Routes are populated by booted callbacks (`AppRouteServiceProvider`, and the `require` of the cached file), so the hook is registered from inside another booted callback — a hook added straight from `boot()` runs **before** the vendor route exists and silently strips nothing. Keep `mary.toogle-sidebar` and `mary.spotlight`: `<x-main>` calls the first on every page render |
| Spatie `canAny` is FALSE for an unknown permission name | `hasAnyPermission(['nosuchperm'])` returns `false` — it does **not** throw `PermissionDoesNotExist`. So a route gated on a permission that is missing from a deployment's DB 403s **every non-admin** (only role bypasses), silently. Before adding a NEW permission to a gate, either seed it everywhere or reuse an existing one — and remember `PermissionSeeder` alone only re-grants the permissions it explicitly lists |
| `@can`/`@canany` nesting prunes child items | A menu item can be hidden by an ancestor gate, not just its own: `/hr/org-chart` is gated `manage_org_chart|view_hr_dashboard` **and** sits inside the «مدیریت سازمان» group's `@canany`, and «تقویم» sits inside «مدیریت تیکتها». When you add an item or change a gate, add its permission to the parent `@canany` too or the item is unreachable |
| Hardware audit scope is resolved by ONE service (#816) | `App\Services\HardwareAuditScope` (live `n_code` → audit `n_code` snapshot → `persons.u_id`) is shared by `HardwareAuditController` and `HardwareIndexHelpers`. **Deny by default** — a null unit id means out of scope, so a trash row with no `n_code` in its snapshot is *hidden*, never listed (this deliberately changed the old `test_not_restorable_warning…` behaviour). Scope checks run **inside** `rollbackHistoryField()`/`restoreRecord()` before the shape/existence guards, never through `historyHardwareId` (the caller primes it with an authorized `loadHistory()`), and `restoreRecord()` reuses the **original** primary key, and there are **two** implementations of it — `HardwareAuditController::restoreRecord()` (API) and `HardwareIndexHelpers::restoreRecord()` (Livewire) — which must both build the row with `(new Hardware)->forceFill($restoreData)->save()` and then advance the sequence. `Hardware::create()` is **wrong** here: `id` is not in `$fillable`, so it drops the key silently, the row lands under a fresh auto-increment id, the `exists` guard can never see it and the audit trail orphans (#888). The `setval`/`advanceHardwareSequence()` step is **not** redundant once the id is reused — `MAX(id)` already includes the restored row, and advancing the sequence is what stops the next auto-increment colliding with it. |
| `#[Locked]` on `historyHardwareId` (#816) | First use of the attribute in this repo (it lives on the property in `app/Traits/HardwareIndexHelpers.php`, and trait attributes do reach the class). It is **defence in depth only**: the property is written server-side by `loadHistory()`/`fetchHistory()`, and `Locked` throws `CannotUpdateLockedPropertyException` (a 500, not a 422) if a legitimate flow ever lets the client set it. Do not cite it as the scope mitigation |
