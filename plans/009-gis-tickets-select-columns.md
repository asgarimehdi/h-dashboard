# perf: the GIS ticket layer hydrates full ticket rows to emit 7 fields

> **Plan 009** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1145 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Http/Controllers/Api/GisController.php`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The GIS ticket layer hydrates **entire ticket rows** — including the `content` long-text column — for up to 1,000 rows, then maps each to a 7-field feature and discards the rest. Each cache miss (a new bbox, or after an invalidation) pays for DB→app transfer and hydration proportional to the ticket bodies rather than to what the map draws. The sibling hardware endpoint already selects explicitly (documented at `:180-200`); this is a one-line-column-list fix with no output change.

## Current state

- `app/Http/Controllers/Api/GisController.php:264-303`:

```php
$features = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
    $query = Ticket::with('unit:id,name,lat,lng')
        ->whereIn('unit_id', $accessibleIds)
        ->whereHas('unit', function ($q) use ($request) {
            $q->whereNotNull('lat')->whereNotNull('lng');
            $this->applyBbox($q, $request);
        });
    …
    $tickets = $query->limit(1000)->get();

    return $tickets->map(function ($ticket) {
        $unit = $ticket->unit;
        return [ 'type' => 'Feature', 'id' => $ticket->id, … 'properties' => [
            'id' => $ticket->id,
            'ticket_code' => $ticket->ticket_code,
            'title' => $ticket->subject,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'unit' => $unit ? ['id' => $unit->id, 'name' => $unit->name] : null,
        ]];
    })->values()->all();
});
```

- `content` is a `longText` column (`database/migrations/2025_12_26_000001_create_tickets_table.php:17`).
- The correct in-repo shape is the hardware layer at `:180-200` (direct JOIN + explicit select, #404).
- Repo convention + gotcha: start Eloquent chains `Model::query()->with([...])` and put IN-filters where PHPStan can resolve them; `Eloquent\Builder` has no larastan, so adding `@method` annotations is forbidden (it re-types chains repo-wide).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/GisTest.php` (find: `grep -rl "gis/tickets\|GisController" tests/`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Http/Controllers/Api/GisController.php` (the tickets method only)
- The GIS test file

**Out of scope** (do NOT touch):
- The cache key/TTL or `normalizedBbox()` — separate concern.
- The units/hardware layers in the same controller.
- The response shape — it must stay byte-identical.

## Git workflow

- Branch: `perf/gis-ticket-columns`
- Commit style: `perf: select only the ticket columns the GIS layer serializes (#009)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Add the explicit select

```php
$query = Ticket::query()
    ->select('tickets.id', 'tickets.ticket_code', 'tickets.subject', 'tickets.priority', 'tickets.status', 'tickets.unit_id')
    ->with('unit:id,name,lat,lng')
    ->whereIn('unit_id', $accessibleIds)
    ->whereHas('unit', function ($q) use ($request) {
        $q->whereNotNull('lat')->whereNotNull('lng');
        $this->applyBbox($q, $request);
    });
```

Qualify columns with `tickets.` to avoid ambiguity with the `units` JOIN the `whereHas` produces. Keep `with('unit:…')` unchanged.

**Verify**: `XDEBUG_MODE=off php artisan test <GIS test>` → all pass.

### Step 2: Pin the attribute set with a test

Add a test asserting the JSON feature `properties` still contains exactly `id`, `ticket_code`, `title`, `priority`, `status`, `unit` (and that `content` is **not** present in the response). Optionally assert the selected columns via a query-log/`getAttribute('content')` check on the hydrated model is not required — the response assertion is the contract.

**Verify**: `XDEBUG_MODE=off php artisan test <GIS test>` → all pass including the new test.

## Test plan

- New test: create a ticket with a long `content`, request the tickets layer over a bbox containing its unit, assert the feature properties shape and that `content` is absent.
- Also confirm the two filter variants (`priority`, `status`) still narrow results.
- Structural pattern: existing GIS feature tests; seed via the shared trait and `UnitFactory`/`TicketFactory`.
- Verification: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Done criteria

- [ ] The tickets query selects only the 6 needed columns (+ the eager-loaded unit columns)
- [ ] Response properties unchanged; `content` absent from the response (new test)
- [ ] `XDEBUG_MODE=off php artisan test <GIS test>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- `Ticket`'s query builder requires an unselected column for a cast/accessor used in the mapping (e.g. `status_name` is an accessor over `status` — confirm it only reads `status`) — report if so.
- Adding the select changes any GIS test's output.

## Maintenance notes

- Any new field the map feature needs must be added to both the `select()` and the `properties` map; a reviewer should check the two move together.
- Deferred: server-side clustering/limits for low zoom — a separate plan.
