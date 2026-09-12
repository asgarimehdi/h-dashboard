# Plan 002: Revive the dead TicketWorkflowTest

> **Executor instructions**: Follow step by step. Run every verification command and confirm the expected result before continuing. If a STOP condition occurs, stop and report.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: HIGH
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `bc1e38e`, 2026-09-12

## Why this matters
`tests/Feature/TicketWorkflowTest.php` is a PHPUnit-style class whose 7 lifecycle methods lack
the `test_` prefix (and any `#[Test]`/`@test` marker), so **none of them execute**. They are the
only tests asserting that a ticket's `accepted_at`/`completed_at` timestamps are auto-set on
status transitions — meaning the ticket lifecycle transition logic is currently untested in CI
while masquerading as covered (`covers(Ticket::class)`). Any regression to those transitions
ships silently.

## Current state
- `tests/Feature/TicketWorkflowTest.php:18-115` — methods:
  `ticket_has_created_status_by_default`, `ticket_can_be_forwarded`,
  `ticket_accepted_sets_accepted_at`, `ticket_completed_sets_completed_at`,
  `ticket_rejected_status_works`, `ticket_timestamps_are_cast`,
  `ticket_factory_produces_valid_data`.
- Class is `extends TestCase` (PHPUnit style). Pest auto-runs `test_*` (snake) and closures,
  but these bare method names are not picked up.
- `ticket_timestamps_are_cast` and `ticket_factory_produces_valid_data` are factory/casting
  sanity checks, not transition tests — verify whether they still make sense after the rename.

## Commands you will need
| Purpose | Command | Expected |
|---------|---------|----------|
| Run one file | `XDEBUG_MODE=off php artisan test tests/Feature/TicketWorkflowTest.php` | currently reports "No tests found" (or 0 tests) — baseline to prove the fix |
| After fix | same command | 7 (or 5–6 real) tests run |
| Format | `vendor/bin/pint --dirty` | clean |

## Scope
**In scope**: `tests/Feature/TicketWorkflowTest.php`.
**Out of scope**: `app/Models/Ticket.php`, controllers, migrations.

## Git workflow
- Branch off `rebecca`; commit `test: revive TicketWorkflowTest lifecycle assertions`.

## Steps

### Step 1: Prove the tests are currently dead
Run `XDEBUG_MODE=off php artisan test tests/Feature/TicketWorkflowTest.php` and record that it
runs zero tests (or fails to discover them).
**Verify**: output confirms 0 tests discovered.

### Step 2: Rename methods to the `test_*` convention
Rename each lifecycle method to `test_<name>` (e.g. `ticket_accepted_sets_accepted_at` →
`test_ticket_accepted_sets_accepted_at`). For the two non-transition helpers
(`ticket_timestamps_are_cast`, `ticket_factory_produces_valid_data`), decide: keep as real tests
or fold into the transition tests.
**Verify**: `php artisan test tests/Feature/TicketWorkflowTest.php` now discovers and runs them.

### Step 3: Make them actually green
Run and fix any assertion that fails against the real `Ticket` model behavior (statuses, casts,
accepted/completed timestamps). Cross-check against `app/Models/Ticket.php` and the
`TicketController::accept/complete/reject` methods.
**Verify**: all lifecycle tests pass.

### Step 4: Format + run suite slice
`vendor/bin/pint --dirty` then `XDEBUG_MODE=off php artisan test tests/Feature/TicketWorkflowTest.php`.
**Verify**: clean, green.

## Test plan
- The revived tests themselves ARE the deliverable: ticket default status, forward, accept→`accepted_at`,
  complete→`completed_at`, reject.

## Done criteria
- `TicketWorkflowTest` discovers and runs its tests (no "0 tests").
- All pass.
- Pint clean.

## STOP conditions
- A transition test fails because the model genuinely does NOT set the timestamp — that is a
  **real bug**, not a test bug: stop and report the discrepancy rather than weakening the assertion.