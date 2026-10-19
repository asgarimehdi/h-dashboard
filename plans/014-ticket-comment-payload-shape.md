# perf: ticket and comment API list payloads ship full bodies

> **Plan 014** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, api, P2` · formerly GitHub issue #1150 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- app/Http/Resources/TicketResource.php app/Http/Controllers/Api/TicketController.php app/Http/Controllers/Api/TicketCommentController.php app/Models/TicketComment.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (the field split must be confirmed by the operator — see STOP conditions)
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The ticket **list** endpoint returns the full `content` long-text body for every row, and the comment list returns raw `TicketComment` models — including both `body` and `body_html` (each up to 10,000 chars) plus the full `reactions` set — with an **unbounded** reply tree when `threaded=1`. Mobile clients pay this on every list refresh, and the payload grows with body size for screens that only render a title/status. The goal is to make list payloads proportional to what lists display, without breaking the mobile contract.

## Current state

- `app/Http/Resources/TicketResource.php:36-66` — `'content' => $model->content` is returned for every row (`:40`).
- `app/Http/Controllers/Api/TicketController.php:41` — the list paginates 20 rows, so each page ships 20 full bodies.
- `app/Http/Controllers/Api/TicketCommentController.php:52-65` — raw models, no resource:

```php
$query = $ticket->comments()->with(['user:id,n_code', 'reactions'])->whereNull('parent_id')->latest();
if ($threaded) {
    $comments = $query->with('children.user', 'children.reactions')->paginate($perPage);
} else {
    $comments = $query->paginate($perPage);
}
return response()->json(['data' => $comments->items(), 'meta' => [ … ]]);
```

- `app/Models/TicketComment.php:79` — the `children` relation force-loads `user.person`.
- `body` and `body_html` are `longText` (`grep -n "body" database/migrations/*ticket_comment*`).
- The mobile client is a **separate Flutter repo** — it is not in this repository, so its field usage cannot be verified by grep here. Treat field removal as requiring operator confirmation.
- Repo conventions: resources live under `app/Http/Resources/`; API routes carry Sanctum abilities + Spatie permissions (see `docs/agents/api-auth.md`); `NotificationResource`/`TodoResource` are the existing resource exemplars.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/Api/TicketApiTest.php tests/Feature/ApiEndpointTest.php` (find: `grep -rl "api/tickets" tests/`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `app/Http/Resources/TicketResource.php`
- `app/Http/Resources/TicketCommentResource.php` (new, if the split is confirmed)
- `app/Http/Controllers/Api/TicketController.php` (list method)
- `app/Http/Controllers/Api/TicketCommentController.php`
- The API test files

**Out of scope** (do NOT touch):
- Route middleware, token abilities, and permission gates — `docs/agents/api-auth.md` is a settled contract.
- The write paths' validation (`body` max 10,000, the `MAX_MENTIONS` cap).
- The web UI ticket pages.

## Git workflow

- Branch: `perf/api-ticket-payload-shape`
- Commit style: `perf: bound ticket and comment list payloads (#014)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Bound threaded replies (safe, no contract change)

In `TicketCommentController::index()`, bound the reply tree per parent (a reply cap, or select only the columns the client renders). Keep field **names** unchanged in this step. Also select explicit columns for comments rather than returning every column plus `reactions` (e.g. `id, ticket_id, parent_id, user_id, body, body_html, is_system, created_at, updated_at`).

**Verify**: `XDEBUG_MODE=off php artisan test <api ticket/comment tests>` → all pass.

### Step 2: Cap the `children` relation's eager loads

Change `TicketComment.php:79`'s `children` relation so it does not force-load `user.person` by default (or limit it to the columns the view needs), and drop the redundant `children.reactions` load if the client does not read reactions per reply. Verify against the existing tests before removing anything.

**Verify**: focused tests pass.

### Step 3: Confirm the mobile contract before cutting fields

Ask the operator (or check the Flutter repo if available) whether the **list** response's `content` and a comment's `body_html` are read client-side. Two acceptable outcomes:

- **Confirmed unused in lists** → add `TicketResource::collection` vs a `TicketDetailResource`, or a `when()` conditional on a `?detail=1` param / route; move `content` to the detail response only. Add a `TicketCommentResource` that emits one of `body`/`body_html`.
- **Unknown / still used** → **stop at step 2** and report: keep the fields, document the finding, and let the operator schedule the split.

Do not silently drop fields.

**Verify**: state in the PR which outcome applied; if fields were cut, new tests assert the list omits them and the detail includes them.

## Test plan

- New tests in the API ticket/comment test files:
  1. List response contains no `content` (only if step 3 confirmed) and still contains the metadata fields.
  2. Detail response still contains `content`.
  3. `threaded=1` with a parent having 50 replies returns at most the cap.
  4. Existing pagination/meta shape unchanged.
- Structural pattern: existing API tests mint **real Bearer tokens** with explicit abilities (`tests/Feature/ApiAbilityTest.php` is the pattern) — do not use bare `Sanctum::actingAs()`.
- Verification: `XDEBUG_MODE=off php artisan test <api tests>` → all pass.

## Done criteria

- [ ] Threaded replies are bounded (new test)
- [ ] Comment list selects explicit columns (no `SELECT *` + full reactions) — verified by reading the query
- [ ] Field removals, if any, are backed by operator confirmation recorded in the PR description
- [ ] `XDEBUG_MODE=off php artisan test <api tests>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- The Flutter contract cannot be confirmed — do **not** remove `content`/`body`/`body_html`; stop at step 2 and report.
- Any API test asserts the current raw-model comment shape in a way that implies a client dependency — report before changing it.
- Bounding replies changes a documented behaviour (e.g. the client renders full threads) — report.

## Maintenance notes

- If the list/detail split lands, `docs/agents/api-auth.md`'s endpoint tables may need a note that list vs detail shapes differ; update the docs commit alongside.
- Reviewer should scrutinize: that pagination meta is untouched, that `threaded=0` behaviour is unchanged, and that no field silently disappeared without confirmation.
- Deferred: trimming `body_html` (server-rendered HTML) from all responses — depends on the client decision.
