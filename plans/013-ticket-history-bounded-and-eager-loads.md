# perf: ticket history is unbounded and eager loads are lost on hydration

> **Plan 013** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1149 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Traits/TicketsInboxFlow.php resources/views/livewire/tickets resources/views/livewire/tickets/ticket-comments.blade.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW-MED
- **Depends on**: none (pairs with the ticket-payload issue; either order works)
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Two problems compound on the ticket detail modals. First, the activity/attachment history is eager-loaded with **no bound and no column selection**, so opening a long-lived ticket pays for its entire history. Second, the loaded ticket is held in a **public** Livewire model property, and Livewire re-fetches public models by key on hydration **without relations** — so after any update the modal's eager loads are gone and its relations lazy-load again. Both are fixed by bounding the history and moving the loaded ticket out of public hydrated-model state.

## Current state

- `app/Traits/TicketsInboxFlow.php:59` — `public ?Ticket $showingTicket = null;`
- `:112-128` (inside `showTicket($id)`) — unbounded eager loads, applied on first load only:

```php
$ticket = Ticket::query()
    ->with([
        'attachments',
        'activities.attachments',
        'activities.user' => fn ($q) => ActorLabel::eagerLoadActor($q),
        'user' => fn ($q) => ActorLabel::eagerLoadActor($q),
        'unit',
    ])
    ->whereIn('unit_id', $accessibleIds)
    ->find($id);
```

- `resources/views/livewire/tickets/⚡inbox.blade.php:608,622,634` — reads `$this->showingTicket->attachments`, `->activities`, and per-activity attachments after hydration.
- `resources/views/livewire/tickets/ticket-comments.blade.php:25` — `public ?Ticket $ticket = null;` and `:47-58` `loadTicket()` eager-loads `comments.user.person` + `children.user.person`. The view reads `$comment->user?->person?->f_name` per comment (`:218-229`). `addComment()` calls `loadTicket()` again (`:86`), so some paths are covered — but any other update re-hydrates without relations.
- `resources/views/livewire/tickets/⚡monitoring.blade.php:155-163` — repeats the same eager-load shape in its second modal.
- Repo conventions: #1040 moved heavyweight public props to `#[Computed]` / private memo (see `units/index.blade.php`, `users/index.blade.php`); Livewire dehydrates only **public** properties.
- Repo gotcha: every `$x->user` is nullable (`SoftDeletes` keeps the row, resolves `null`) — keep the existing `ActorLabel::eagerLoadActor()` usage; it exists for that reason (#906).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/TicketsInboxLivewireTest.php tests/Feature/TicketCommentsLivewireTest.php` | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Traits/TicketsInboxFlow.php`
- `resources/views/livewire/tickets/⚡inbox.blade.php`
- `resources/views/livewire/tickets/⚡monitoring.blade.php`
- `resources/views/livewire/tickets/ticket-comments.blade.php`
- The ticket inbox/comment Livewire tests

**Out of scope** (do NOT touch):
- Authorization/scope logic (`showTicket`'s `whereIn('unit_id', $accessibleIds)`, permission gates #1055) — only the loading/caching of the model changes.
- The activity timeline's visual structure or ordering semantics (oldest→newest).
- `TicketResource` / API responses (that is the paired payload issue).
- The `ActorLabel` contract.

## Git workflow

- Branch: `perf/ticket-history-bounded`
- Commit style: `perf: bound ticket history and keep loaded tickets out of public state (#013)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Move the loaded ticket to a computed/private loader

Replace `public ?Ticket $showingTicket` with public **scalar** state (`public ?int $showingTicketId = null;` is already present as `$showingTicketId` in the trait) and a `#[Computed] public function showingTicket(): ?Ticket` that performs the same scoped query with the same `with([...])`. `#[Computed]` re-runs on each request, so relations are always present after an update. Update the Blade reads to `$this->showingTicket`.

Keep the scope check identical. `#[Computed]` is per-request, so the query runs at most once per update if you also assign it to a local in the methods that need it.

**Verify**: `grep -n "public ?Ticket \$showingTicket" app/Traits/TicketsInboxFlow.php` → no match; focused tests pass.

### Step 2: Bound the history

Limit activities to the newest N with a deterministic order, and select only the columns the modal renders. Example:

```php
->with([
    'attachments:id,ticket_id,file_name,file_path,file_size,activity_id',
    'activities' => fn ($q) => $q->latest('id')->limit(50)
        ->with(['attachments', 'user' => fn ($q) => ActorLabel::eagerLoadActor($q)]),
    'user' => fn ($q) => ActorLabel::eagerLoadActor($q),
    'unit',
])
```

Keep the modal's display order (the view sorts in PHP at `⚡inbox.blade.php:622` — verify it still reads sensibly with a capped, newest-first set; if the view reverses, do it there). Add a "load older" affordance only if the product needs it; otherwise a cap with a note in the PR is acceptable — state which you chose.

**Verify**: focused tests pass; a long-history fixture renders ≤ the cap.

### Step 3: Fix `ticket-comments` the same way

Replace `public ?Ticket $ticket` with `public ?int $ticketId` + a `#[Computed] ticket()` that runs the same scoped `loadTicket()` query (including `comments.user.person` and `children.user.person`). Remove the now-unnecessary `loadTicket()` calls that existed only to repair hydration (`:86`), or keep the method as the single query body the computed calls.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/TicketCommentsLivewireTest.php` → all pass.

### Step 4: Lock it with a test

Add a test that, after an update (e.g. posting a comment or toggling something), the number of queries does not grow with comment/activity count — use the shared trait's `assertQueryCount()`/`assertNoNPlusOne()` ceiling.

**Verify**: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Test plan

- New tests:
  1. A ticket with 120 activities renders at most the cap, newest-first.
  2. Posting a comment on a 30-comment ticket does not issue a query per comment (query-count ceiling / `assertNoNPlusOne`).
  3. `showingTicket`/`ticket` remain scoped: an out-of-scope id yields nothing (existing behaviour, keep pinned).
- Structural pattern: `tests/Feature/TicketsInboxLivewireTest.php` (asserts against the database, per repo rule) and `TicketsInboxFlow`'s existing scope tests.
- Verification: `XDEBUG_MODE=off php artisan test <files>` → all pass.

## Done criteria

- [ ] `grep -rn "public ?Ticket \$showingTicket\|public ?Ticket \$ticket" app/Traits/TicketsInboxFlow.php resources/views/livewire/tickets/` → no matches
- [ ] Activities are bounded (new test) and attachments are column-limited
- [ ] A query-ceiling test proves no per-comment/per-activity N+1 after an update
- [ ] Scope checks unchanged (`git diff` shows no change to the `whereIn(unit_id …)` predicates)
- [ ] `XDEBUG_MODE=off php artisan test <ticket tests>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- A `#[Computed]` ticket cannot be used because a method mutates `$this->showingTicket` and reads it back in the same request — report the method; a private non-dehydrated property plus an explicit reload may be the right shape instead.
- Capping activities hides data the modal's workflow requires (e.g. an attachment on a very old activity) — report before capping.
- An existing test asserts the full history is present.

## Maintenance notes

- If a "load older activities" action is added later, it must extend the computed's limit rather than reintroduce a public model property.
- Reviewer should scrutinize: that the modal still shows the *latest* activity on open, and that posting a comment does not re-query per row.
- Deferred: paginating the activity timeline in the UI.
