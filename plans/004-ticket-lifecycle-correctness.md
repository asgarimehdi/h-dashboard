# Plan 004: Ticket Lifecycle Correctness (state guards, locks, reopen)

> **Executor instructions**: Follow step by step. Run every verification command and confirm expected output before moving on. If STOP conditions occur, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat bdd8e10..HEAD -- app/Http/Controllers/Api/TicketController.php "resources/views/livewire/tickets/⚡inbox.blade.php" "resources/views/livewire/tickets/⚡create.blade.php" routes/api.php` → expect no output. If output exists, compare excerpts below against live code first.

## Status

- **Priority**: P1 (closed tickets silently reopen; concurrent accepts race; success toast lies)
- **Effort**: M
- **Risk**: MED (state-machine changes affect reporting semantics — reopen must be audit-logged)
- **Depends on**: 001 (needs green baseline; behavior changes must not hide behind red tests)
- **Category**: bug
- **Planned at**: commit `bdd8e10`, 2026-09-14

## Why this matters

The API `assign()` resurrects `completed`/`rejected` tickets with no guard and no activity row. API `accept`/`complete` skip the pessimistic lock the Livewire inbox already has, so concurrent accepts double-write. The inbox itself shows success even when the ticket was already taken (early `return` inside a transaction closure only exits the closure).

## Current state

- `app/Http/Controllers/Api/TicketController.php:147` (verified): `$ticket->update(['current_assignee_id' => ..., 'status' => 'forwarded'])` unconditional. `store()` at :81 builds `'ticket_code' => 'T-'.strtoupper(Str::random(8))` (no unique DB constraint on that column — CORR-12 collision claim was REJECTED, do not touch).
- API `accept`/`complete` at :158-206: plain `$ticket->update()`, no transaction/`FOR UPDATE`.
- Livewire `⚡inbox.blade.php:444-447` (subagent-verified, re-check live): `DB::transaction(fn() => DB::select('SELECT id, status FROM tickets WHERE id = ? FOR UPDATE', ...))`; `:448-477`: early `return` inside closure then unconditional success toast at :477.
- Inbox completion `:595-608`: `$file->store()` runs BEFORE `DB::commit()` → orphan files on rollback.
- `routes/api.php:102-107`: `assign/accept/complete`, no `reopen/reject`. Comment update/destroy at :116-119 require `manage_unit_tickets` (author can't edit own comment).
- Reporting impact: SLA/waiting-duration metrics corrupt on silent reopen; duplicates from workarounds corrupt counts further.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Ticket tests | `XDEBUG_MODE=off php artisan test tests/Feature/TicketApiTest.php tests/Feature/TicketsInboxLivewireTest.php tests/Feature/TicketCommentsLivewireTest.php` | pass |
| Full | `composer test` | pass |

## Scope

**In scope**: `TicketController.php` (assign/accept/complete + new reopen), `⚡inbox.blade.php` (toast branch + file-after-commit), `routes/api.php` (reopen route only), `TicketCommentController.php` author-edit policy line only if trivially safe (else defer with note).
**Out of scope**: domain-event refactor (plan 010 covers DEBT-07 — do NOT build TicketService here), notification fan-out changes, report-metric recomputation.

## Steps

### Step 1: Guard `assign` + lock `accept`/`complete`

`assign()`: reject unless `$ticket->status` in `['created','forwarded','accepted']` → 422 `Ticket is closed and cannot be reassigned.` Wrap `accept`/`complete` in `DB::transaction` + `SELECT ... FOR UPDATE` + re-check status, mirroring inbox :444-447.
**Verify**: assign on completed → 422, status unchanged; ticket suites pass.

### Step 2: Honest inbox toast

Return bool from the transaction closure; branch the toast + `closeDetail` on it (taken → warning toast, no success). Keep Persian copy consistent with surrounding toasts.
**Verify**: simulated double-accept (two actors) → second sees "already taken", not success.

### Step 3: Files after commit

Move `$file->store()` calls to after `DB::commit()` (collect validated uploads first, persist records in tx, store files + update paths after). On file-store failure, log + surface error (record exists, files missing — loud, not silent).
**Verify**: forced tx rollback leaves no orphan files (check storage dir before/after test).

### Step 4: `reopen` endpoint (audit-logged)

`POST /api/tickets/{ticket}/reopen`: `completed|rejected → created` (or `forwarded`? match inbox semantics — check live inbox reject/forward vocabulary first), writes activity row, requires same gate as `assign`. Define reporting semantics in code comment (reopened tickets count as new open from `reopened_at`).
**Verify**: reopen completed → 200 + activity row; reopen open ticket → 422.

### Step 5: Comment author edit (only if safe)

If `TicketCommentController` update path is a one-line gate (`author within N min OR manage_unit_tickets`), do it + test. Else leave + note in plan file why deferred.
**Verify**: author edits own fresh comment → 200; stranger → 403.

## Test plan

- New Pest: assign-closed 422, concurrent-accept single-winner, reopen round-trip + activity row, no-orphan-files, author-edit cases.
- Existing ticket suites (incl `TicketApiTest:156-251` lifecycle) pass unweakened.

## Done criteria

- [ ] `assign` rejects closed tickets (422)
- [ ] `accept`/`complete` use FOR UPDATE + re-check
- [ ] Inbox toast branches on taken vs accepted
- [ ] File stores happen post-commit
- [ ] `reopen` exists, audit-logged, tested
- [ ] No out-of-scope refactors

## STOP conditions

- Inbox transaction/lines differ materially from excerpts → stop, report live code.
- Reopen semantics conflict with a reporting query (define first; if ambiguous, stop and ask).
- Verification fails twice → stop, report.
