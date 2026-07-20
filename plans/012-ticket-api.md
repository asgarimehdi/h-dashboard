# Plan 012: Ticket REST API — CRUD + workflow actions for Flutter

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat ac23e15..HEAD -- app/Http/Controllers/Api/ app/Http/Resources/ routes/api.php app/Models/Ticket.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED — changes public API shape, must not break web frontend
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `ac23e15`, 2025-07-20
- **Issue**: —

## Why this matters

The Ticket system is fully built on the web (create, inbox, monitoring views,
772-line inbox component). The Flutter mobile app documented in the README has
no ticket surface because the API has no ticket endpoints. A REST API unlocks
the mobile workflow.

## Current state

**`routes/api.php`** (lines 44–50) — only Todo endpoints exist:
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    // no ticket routes
```

**`app/Models/Ticket.php`** — fillable + status workflow:
```php
protected $fillable = [
    'ticket_code', 'user_id', 'unit_id', 'subject', 'content',
    'priority', 'status', 'task_id', 'current_assignee_id',
    'accepted_at', 'completed_at',
];
```
Statuses: `created` → `forwarded` → `accepted` → `completed` | `rejected`.
Relations: `user` (creator), `assignee`, `unit`, `attachments`, `activities`.

**`app/Http/Resources/TodoResource.php`** — exemplar JsonResource pattern:
```php
return [
    'id' => $this->id, 'title' => $this->title, ...
    'created_at' => $this->created_at,
];
```

**`app/Http/Controllers/Api/TodoController.php`** — full CRUD with access
control; use as structural template.

**`tests/Feature/TicketWorkflowTest.php`** — model-only tests (assert status
transitions); does NOT call any API endpoint.

Repo conventions: Pest, `RefreshDatabase`, `User::factory()`, `Unit::factory()`,
response shape always `{ success: true, data: ... }` for mutations.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests | `php artisan test` | exit 0, all pass |
| Lint | `vendor/bin/pint` | exit 0 |

## Scope

**In scope**:
- `app/Http/Controllers/Api/TicketController.php` — create
- `app/Http/Resources/TicketResource.php` — create
- `routes/api.php` — add ticket routes
- `tests/Feature/TicketApiTest.php` — create

**Out of scope**:
- Any change to web Livewire components (`resources/views/livewire/tickets/`)
- Attachment upload (file handling on API is a separate concern)
- TaskActivity logging — API operates on Ticket entities only
- `task_id` linking (Ticket→Todo link) — skip for now

## Git workflow

- Branch: `feature/ticket-api`
- Commit style: `feat: add ticket REST API` (+ a commit per step if needed)
- Do NOT push or open a PR unless the operator instructed it

## Steps

### Step 1: Create TicketResource

Create `app/Http/Resources/TicketResource.php` matching `TodoResource.php`
pattern. Include: `id`, `ticket_code`, `subject`, `content`, `priority`,
`status`, `unit_id`, `current_assignee_id`, `accepted_at`, `completed_at`,
`created_at`, `updated_at`. Include nested `user` (name + n_code) and
`assignee` (name + n_code) via `whenLoaded`, and `unit` (id + name) via
`whenLoaded`.

**Verify**: `vendor/bin/pint app/Http/Resources/TicketResource.php`

### Step 2: Create TicketController

Create `app/Http/Controllers/Api/TicketController.php` with these methods:

- `index(Request)` — `Ticket::accessible(withRelated: true)` (from
  `HasOrganizationalScope` trait), paginate 15, return
  `TicketResource::collection()`
- `store(Request)` — validate: `subject` (required, min 3), `content`
  (required), `priority` (in: low,normal,high,critical), `unit_id`
  (required, exists:units,id), `current_assignee_id` (nullable,
  exists:users,id). Check unit_id is in accessible units (reuse pattern
  from `TodoController::store`). Generate `ticket_code` via
  `Ticket::create()->ticket_code` or a static helper — check how the web
  component generates it. Set `user_id` = `$request->user()->id`,
  `status` = `created`. Return 201 with `TicketResource`.
- `show(Ticket)` — return 403 if unit not in accessible; return
  `TicketResource` with `load(['user.person', 'assignee.person', 'unit', 'attachments'])`.
- `update(Request, Ticket)` — same auth check; validate: `subject`,
  `content`, `priority`, `current_assignee_id` (all `sometimes`). Return
  updated `TicketResource`.
- `destroy(Ticket)` — soft-delete via `$ticket->delete()`. Return 200.
- `accept(Ticket)` — check auth, set `status = 'accepted'`,
  `accepted_at = now()`. Return `TicketResource`.
- `forward(Ticket, Request)` — validate `unit_id` (required) or
  `current_assignee_id` (required). Set `status = 'forwarded'`. Return
  `TicketResource`.
- `complete(Ticket)` — check `status === 'accepted'` (guard), set
  `status = 'completed'`, `completed_at = now()`. Return `TicketResource`.

**Verify**: `vendor/bin/pint app/Http/Controllers/Api/TicketController.php`

### Step 3: Register routes in `routes/api.php`

Add inside the existing `auth:sanctum` group (after the todo routes):
```php
Route::get('/tickets', [TicketController::class, 'index']);
Route::post('/tickets', [TicketController::class, 'store']);
Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
Route::put('/tickets/{ticket}', [TicketController::class, 'update']);
Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy']);
Route::post('/tickets/{ticket}/accept', [TicketController::class, 'accept']);
Route::post('/tickets/{ticket}/forward', [TicketController::class, 'forward']);
Route::post('/tickets/{ticket}/complete', [TicketController::class, 'complete']);
```

**Verify**: `php artisan route:list --path=api/tickets` shows 8 routes

### Step 4: Write Pest feature tests

Create `tests/Feature/TicketApiTest.php` following
`tests/Feature/TodoApiTest.php` as pattern (read it for structural reference).
Cover: unauthenticated → 401, ticket creation, ticket show, update,
soft-delete, forward, accept, complete (including 403 when unit not
accessible). Use `User::factory()->create()`, `Unit::factory()->create()`,
`Ticket::factory()->create()`.

**Verify**: `php artisan test tests/Feature/TicketApiTest.php`

## Test plan

- `test_unauthenticated_returns_401` — request without token → 401
- `test_create_ticket` — authed user creates ticket → 201, response has
  `ticket_code`, `status = created`
- `test_create_ticket_unauthorized_unit_returns_403` — create with
  inaccessible unit → 403
- `test_show_ticket` — returns ticket with loaded relations
- `test_show_ticket_unauthorized_returns_403`
- `test_update_ticket` — update subject → changed
- `test_delete_ticket` — soft-delete, subsequent show → 404
- `test_accept_ticket` — sets `status = accepted`, `accepted_at` set
- `test_forward_ticket` — sets `status = forwarded`, unit/assignee set
- `test_complete_ticket` — sets `status = completed`, `completed_at` set
- `test_complete_ticket_requires_accepted_status` — complete without
  accept → 422 or 400 (implement a guard, return appropriate error)

Pattern: see `tests/Feature/TodoApiTest.php`.

## Done criteria

- [ ] `php artisan test` exits 0
- [ ] `vendor/bin/pint` exits 0
- [ ] `php artisan route:list --path=api/tickets` shows 8 routes
- [ ] `tests/Feature/TicketApiTest.php` exists and has ≥10 test cases,
  all passing
- [ ] No files outside the in-scope list are modified (`git status`)

## STOP conditions

Stop and report back (do not improvise) if:

- The `ticket_code` generation strategy in the existing codebase differs
  significantly from what this plan assumes — investigate and report.
- Any validation rule would reject a ticket created via the existing web
  component.
- The `accessible()` scope on `Ticket` is not working as expected (test it
  manually before writing controller logic).

## Maintenance notes

- If `Ticket` model gains new fields (e.g. `task_id`), add them to
  `TicketResource` + validation in the same PR.
- Attachment uploads will need a separate plan with `WithFileUploads` and
  storage configuration.
- The `task_id` → Todo link should be exposed in a later plan if Flutter
  needs it.