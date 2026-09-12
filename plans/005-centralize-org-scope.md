# Plan 005: Centralize organizational-scope filtering and access checks

> **Executor instructions**: Follow step by step. Run every verification command and confirm the expected result before continuing. If a STOP condition occurs, stop and report.

## Status
- **Priority**: P2
- **Effort**: M
- **Risk**: HIGH
- **Depends on**: none (but big — do it as its own focused PR)
- **Category**: tech-debt
- **Planned at**: commit `bc1e38e`, 2026-09-12

## Why this matters
The organizational-scope boundary is the single most security-critical invariant in the app
(unit isolation across hardware/tickets/persons). Today it is expressed **three different ways**
copy-pasted across 12+ files:

- `whereIn('u_id', $accessibleIds)` (PersonController, HrAnalytics, Hardware, Gis, Report…)
- `whereHas('person', fn($q) => $q->whereIn('u_id', ...))` (hardware/index, tools, dashboard, search…)
- `whereIn('unit_id', ...)` (TicketController, ReportController, UnitController…)

A `scopeAccessible()` helper **already exists** (`app/Traits/HasOrganizationalScope.php:10`) and is
used only for Ticket/Person/Todo in ~11 blade call sites — dozens of hand-rolled copies remain.
Every divergent copy is a chance for an IDOR regression when a future controller forgets the check
(see `IDOR-01`: `UnitScopedRequest::authorize()` returns `true` unconditionally, delegating auth
to hand-written checks).

## Current state
- `app/Traits/HasOrganizationalScope.php` — `scopeAccessible()` + `HasOrganizationalScope` trait.
- `app/Http/Requests/UnitScopedRequest.php:28` — `assertAccessibleUnit()` (a `JsonResponse|true`
  union used awkwardly by callers).
- `TicketCommentController.php` repeats the 403 check 6×; `HardwareController`/`HardwareAuditController`
  define private `assertAccessible`/`assertAccessibleFromAudit` variants — four idioms.
- Raw `whereIn('u_id', ...)` / `whereHas('person', ...)` scattered per the backlog.

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Full test | `composer test` (or `XDEBUG_MODE=off php artisan test`) | pass |
| Format | `vendor/bin/pint --dirty` | clean |
| Static scope check | `grep -rn "accessibleUnitIds\|whereIn('u_id'\|whereIn('unit_id'" app/ resources/views/livewire/` | decreasing over time |

## Scope
**In scope**: `app/Traits/HasOrganizationalScope.php`, `app/Http/Requests/UnitScopedRequest.php`,
all Api controllers doing manual scope checks, and (incrementally) the Livewire blades with raw
scope queries.
**Out of scope**: migrations, schemas, the AccessService CTE itself (already correct/cached).

## Git workflow
- Branch off `rebecca`; this is best done as **one mechanical refactor commit** + a separate
  **behavior-preserving test pass**. Commit `refactor: centralize org-scope filtering`.

## Steps

### Step 1: Make scopeAccessible the canonical path
Extend `scopeAccessible()` (or add a sibling) to cover the two missing variants: a
`whereHas('person', ...)` form for models that scope through `person`, and a `unitColumn`
parameter so Ticket/Unit (which use `unit_id`) can reuse it.
**Verify**: unit-test the three variants return identical results to the raw queries they replace.

### Step 2: Replace raw queries in controllers
Mechanically replace `whereIn('u_id', $accessibleIds)` / `whereIn('unit_id', ...)` /
`whereHas('person', ...)` with the scope in each Api controller, verifying each transformed query
is behavior-identical.
**Verify**: `composer test` green after each batch.

### Step 3: Unify the access-check helper
Make `assertAccessibleUnit()` throw a typed exception (mapped to a JSON 403 by the exception
handler) or return `void`, so callers don't special-case a union return. Collapse the private
`assertAccessible`/`assertAccessibleFromAudit` variants into the one helper.
**Verify**: 403 responses for out-of-scope access remain JSON-shaped and identical.

### Step 4: Replace raw scope queries in the Livewire blades
Apply the same scope to the blades listed in the backlog (hardware/index, tools, dashboard,
search, activity-log). This can be a follow-up commit to keep the diff reviewable.
**Verify**: `grep` shows no remaining raw scope queries; `composer test` green.

## Test plan
- Unit tests for `scopeAccessible()` variants.
- Existing integration tests (PersonApi, Hardware, Ticket, HrAnalytics, Report) are the regression
  net — they must stay green unchanged (proving behavior preserved).

## Done criteria
- `scopeAccessible()` (and variants) is the only org-scope path in `app/` and the blades.
- One `assertAccessible...` helper; no per-controller copies.
- `grep` of raw scope patterns in `app/` returns empty (or only in `AccessService`).
- `composer test` fully green.

## STOP conditions
- A transformed query yields different rows (e.g. because of `parent_id` nested-scope semantics
  vs `u_id` direct) — stop and capture the exact semantic difference; do NOT flatten two different
  scope meanings into one scope that loses the distinction.