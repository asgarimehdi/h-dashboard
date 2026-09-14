# Plan 005: Model & Query Correctness (reactions, ancestors, recurrence, comments, sanitizer)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Models/TicketComment.php app/Models/Unit.php app/Console/Commands/GenerateRecurringTodos.php app/Console/Commands/GenerateDueMaintenance.php resources/views/livewire/tickets/ticket-comments.blade.php app/Http/Controllers/Api/TicketCommentController.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P2 (wrong counts + dropped ancestors + duplicate todos on retry; XSS sanitizer is defense-in-depth)
- **Effort**: M
- **Risk**: MED (query rewrites need EXPLAIN-level care on PostGIS/pg_trgm paths — keep result shapes identical)
- **Depends on**: 001
- **Category**: bug
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

Five small correctness bugs compound: reaction counts come from an invalid `withCount` subquery, `ancestorIds()` returns only direct parents despite its name, the recurring-todo generator duplicates on crash/overlap, deep comment threads N+1, and the stored-HTML pipeline rests on a hand-rolled regex sanitizer with no purifier backstop.

## Current state

- `app/Models/TicketComment.php:103-109` (verified):
```php
return $query->withCount(['reactions as reaction_counts' => function ($q) {
    $q->selectRaw('reaction, count(*)')->groupBy('reaction');
}]);
```
Multi-row subselect inside `withCount` — wrong counts.
- `app/Models/Unit.php:121-133` (verified): `ancestorQuery()` is a single `JOIN ... ON parent.id = base.parent_id`, no recursion; `descendantIds()` uses a recursive CTE. Callers `maps/point.blade.php:77`, `maps/interactive.blade.php:18` merge "ancestors" but lose grandparent+ levels.
- `app/Console/Commands/GenerateRecurringTodos.php:39-53` (verified): `Todo::create(...)` then `$template->update(...)`, no transaction, no duplicate check — unlike `GenerateDueMaintenance.php:41-45` which guards on existing tickets.
- `app/Models/TicketComment.php:57`: `children()->with('user.person')`; controller `:37` loads `with('children.user','children.reactions')`; depth cap at :77-80 is 3 — level-2+ replies lazy-load (N+1).
- `ticket-comments.blade.php:232,268`: `{!! ... !!}` fed by `TicketCommentController:311-332 processMarkdown()` (`e()` + regex bold/italic/link, `sanitizeUrl` :288-306). Correct today, brittle.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Tests | `XDEBUG_MODE=off php artisan test --filter='TicketComment|Unit|Todo|Recurring|Maintenance'` | pass |
| Full | `composer test` | pass |

## Scope

**In scope**: the files above + `GenerateDueMaintenance.php` (read as pattern only — do NOT change its behavior).
**Out of scope**: ticket state machine (plan 004), CSP (plan 002), performance batching beyond comment depth (plan 007), new purifier package install (use existing deps; if none fits, add tests + assertion only and defer package).

## Steps

### Step 1: Reaction counts

Replace the grouped `withCount` with `withCount('reactions')` + a separate `Reaction::selectRaw('reaction, count(*)')->whereIn(...)->groupBy('reaction')` lookup where per-reaction breakdown is needed. Keep response shape identical.
**Verify**: comment with 2 👍 + 1 ❤️ returns `reactions_count=3` and correct breakdown; suite passes.

### Step 2: Recursive ancestors

Rewrite `ancestorQuery()` as a recursive CTE up `parent_id` (mirror `recursiveDescendantQuery`), same `Collection<int>` return. Re-run map callers unchanged.
**Verify**: 3-level chain returns all 3 ancestors; `maps/point` + `maps/interactive` tests pass.

### Step 3: Idempotent generator

Wrap create+update in `DB::transaction`; skip when an identical instance (same title/unit/start-day) already exists (mirror `GenerateDueMaintenance:41-45` guard style).
**Verify**: run command twice same day → second creates 0; suite passes.

### Step 4: Two-level comment eager load

Add `children.children.user`, `children.children.reactions` to the controller `:37` chain (matches depth-3 cap). Do NOT switch to full `descendants()` (over-fetch).
**Verify**: deep thread renders with flat query count (assert via `DB::enableQueryLog` in test or Telescope-less count).

### Step 5: Sanitizer backstop

Keep `e()`-first pipeline; add assertion/tests for `javascript:`/quote-breakout/`data:` cases against `processMarkdown()` + `sanitizeUrl()`. If an allowlist purifier package already exists in `vendor`, wire it; otherwise tests-only + comment marking the deferred package.
**Verify**: new cases pass; existing comment tests pass.

## Test plan

- New Pest per step (counts, ancestors depth, double-run generator, query-count on thread, xss cases).

## Done criteria

- [ ] Reaction counts correct (scalar + breakdown)
- [ ] Ancestors recursive
- [ ] Generator idempotent + transactional
- [ ] No lazy loads at depth ≤3
- [ ] XSS edge-case tests green
- [ ] No out-of-scope changes

## STOP conditions

- Response-shape change required for reactions (consumers depend on `reaction_counts` scalar) → stop, report consumers.
- CTE recursion hits cycles in data (bad `parent_id` loop) → stop, report; add visited guard.
- Verification fails twice → stop, report.
