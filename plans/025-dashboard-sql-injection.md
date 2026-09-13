# 025 — Fix SQL Injection in Dashboard Raw Queries

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | CRITICAL (Security) |
| **Effort** | M |
| **Risk** | Low — only changes query parameterization |
| **Base commit** | `a106d38` |
| **Files** | `resources/views/livewire/dashboard.blade.php` |

## Problem

`resources/views/livewire/dashboard.blade.php` lines 56–141 embed the user's `$accessibleIds` array directly into raw SQL strings via `"{$ids}"` interpolation. While `$accessibleIds` comes from `AccessService::accessibleUnitIds()` (an integer array derived from DB lookups, not raw user input), this pattern is fragile and violates defense-in-depth. Any future change that feeds unsanitized data into this array becomes an injection vector.

### Evidence (file:line)

- **Line 57:** `$ids = implode(',', $accessibleIds);`
- **Line 60–63:** `"SELECT ... WHERE u_id IN ({$ids})" persons+units query`
- **Line 67–72:** `"SELECT ... WHERE unit_id IN ({$ids})" tickets query`
- **Line 76–81:** `"SELECT ... WHERE unit_id IN ({$ids})" todos query`
- **Line 85–89:** `"SELECT ... WHERE t.unit_id IN ({$ids})" linked todos query`
- **Line 126:** Same pattern for ticket details query
- **Line 133–140:** `$ids` used again in the ticket details raw query

### Current code (excerpt, lines 56–72)

```php
$ids = implode(',', $accessibleIds);

// Persons + Units in one shot
$row = DB::selectOne("
    SELECT
        (SELECT COUNT(*) FROM persons WHERE u_id IN ({$ids})) AS total_persons,
        (SELECT COUNT(*) FROM units WHERE id IN ({$ids})) AS total_units
");

// Tickets: total, open, completed
$ticketRow = DB::selectOne("
    SELECT
        COUNT(*) AS total_tickets,
        COUNT(*) FILTER (WHERE status IN ('created','forwarded')) AS open_tickets,
        COUNT(*) FILTER (WHERE status = 'completed') AS completed_tickets
    FROM tickets WHERE unit_id IN ({$ids})
");
```

## Decision

Replace string-interpolated `IN ({$ids})` with parameterized `DB::select()` using the `?` placeholder and an `array_fill()` of bind values. This is the standard Laravel approach for raw SQL and requires no Eloquent refactoring.

## Commands

```bash
# Verification
cd /home/runner/h-dashboard
composer test -- --filter=DashboardPageTest
XDEBUG_MODE=off php artisan test tests/Feature/DashboardPageTest.php -v
vendor/bin/pint --dirty --format agent
```

## Steps

### Phase 1 — Parameterize persons+units query (lines 60–63)

1. Remove the `$ids = implode(',', $accessibleIds);` line (57).
2. Replace lines 60–63 with:

```php
$placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));
$row = DB::selectOne("
    SELECT
        (SELECT COUNT(*) FROM persons WHERE u_id IN ({$placeholders})) AS total_persons,
        (SELECT COUNT(*) FROM units WHERE id IN ({$placeholders})) AS total_units
", array_merge($accessibleIds, $accessibleIds));
```

### Phase 2 — Parameterize tickets query (lines 67–72)

```php
$ticketRow = DB::selectOne("
    SELECT
        COUNT(*) AS total_tickets,
        COUNT(*) FILTER (WHERE status IN ('created','forwarded')) AS open_tickets,
        COUNT(*) FILTER (WHERE status = 'completed') AS completed_tickets
    FROM tickets WHERE unit_id IN ({$placeholders})
", $accessibleIds);
```

### Phase 3 — Parameterize todos query (lines 76–81)

```php
$todoRow = DB::selectOne("
    SELECT
        COUNT(*) AS total_todos,
        COUNT(*) FILTER (WHERE is_completed = false) AS pending_todos,
        COUNT(*) FILTER (WHERE is_completed = true) AS completed_todos
    FROM todos WHERE unit_id IN ({$placeholders})
", $accessibleIds);
```

### Phase 4 — Parameterize linked todos query (lines 85–89)

```php
$linkedTodos = DB::selectOne("
    SELECT COUNT(DISTINCT t.id) AS cnt
    FROM todos t
    WHERE t.unit_id IN ({$placeholders})
      AND EXISTS (SELECT 1 FROM tickets WHERE task_id = t.id)
", $accessibleIds);
```

### Phase 5 — Parameterize ticket details query (lines 126–140)

```php
$placeholders = implode(',', array_fill(0, count($accessibleIds), '?'));
$row = DB::selectOne("
    SELECT
        COUNT(*) FILTER (WHERE priority = 'urgent' AND status IN ('created','forwarded')) AS urgent,
        COUNT(*) FILTER (WHERE priority = 'normal' AND status IN ('created','forwarded')) AS normal,
        COUNT(*) FILTER (WHERE priority = 'low' AND status IN ('created','forwarded')) AS low,
        COUNT(*) FILTER (WHERE status IN ('created','forwarded') AND deadline < NOW()) AS overdue,
        AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL THEN {$diffExpr} END) AS avg_days
    FROM tickets WHERE unit_id IN ({$placeholders})
", $accessibleIds);
```

Note: `$placeholders` must be re-computed inside each `Cache::remember` closure because `$accessibleIds` is in scope via `use`.

### Phase 6 — Verify

```bash
XDEBUG_MODE=off php artisan test tests/Feature/DashboardPageTest.php -v
vendor/bin/pint --dirty --format agent
```

## Test plan

- Existing `DashboardPageTest` covers page load and data population — re-run confirms correctness.
- Manual: load dashboard with a user who has scoped units, verify stats match.
- Verify cache keys still produce identical results (the output SQL is functionally identical).

## Done criteria

- [ ] Zero instances of `"{$ids}"` in `dashboard.blade.php`
- [ ] All `DB::select` / `DB::selectOne` calls use `?` bind parameters
- [ ] `DashboardPageTest` passes
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If `$accessibleIds` can ever be a string type (not array of ints), STOP and report — the fix assumes integer array.
- If cache miss rate changes noticeably, STOP and profile query plans.
