# Plan 015: Reports REST API — summary + export for Flutter

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat ac23e15..HEAD -- app/Http/Controllers/ routes/api.php resources/views/livewire/reports/index.blade.php`
> If any in-scope file changed, compare "Current state" excerpts before
> proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW — read-only reporting endpoints
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `ac23e15`, 2025-07-20
- **Issue**: —
- **Confidence**: LOW — Flutter reporting requirements not confirmed

## Why this matters

`reports/index.blade.php` (346 lines) generates ticket/todo/person/user reports
with Jalali date ranges and unit filtering — fully functional on web.
Exposing a JSON API unlocks mobile charts. If Flutter doesn't need reports,
this plan is a wasted effort; confirm before executing.

**This is a spike/design plan** — the complexity is low enough that a full
implementation is specified here, but the executor should verify the exact
Flutter data format needs before finalizing response shapes.

## Current state

**`resources/views/livewire/reports/index.blade.php`** — report logic (lines 44–):
- `reportType`: `tickets` | `todos` | `users` | `persons`
- `dateFrom`, `dateTo`: Jalali strings `Y/m/d`
- `selectedUnitId`: filter by unit
- `reportData()` computes three aggregates:
  - `$total` — count
  - `$byDay` — `[{ day: '1404/03/15', count: 12 }, ...]` (sorted asc by day)
  - `$byUnit` — for tickets/todos: `[{ unit_id, count }, ...]`, for others: null

Date parsing: `Jalalian::fromFormat('Y/m/d', $date)->toCarbon()`.
Access: `AccessService::accessibleUnitIds()` used throughout.

**`routes/api.php`** — no report routes exist.

Repo conventions: Pest, `RefreshDatabase`, `Illuminate\Support\Facades\Cache`
for expensive aggregates. No existing report tests.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests | `php artisan test` | exit 0, all pass |
| Lint | `vendor/bin/pint` | exit 0 |

## Scope

**In scope**:
- `app/Http/Controllers/Api/ReportController.php` — create
- `app/Http/Resources/ReportResource.php` — create
- `routes/api.php` — add report routes
- `tests/Feature/ReportApiTest.php` — create

**Out of scope**:
- Any change to web Livewire component
- PDF/CSV export (defer to separate plan)
- Real-time caching of report results
- Filtering by unit hierarchy (only direct unit filter)

## Git workflow

- Branch: `feature/report-api`
- Commit style: `feat: add reports API`
- Do NOT push or open a PR unless the operator instructed it

## Steps

### Step 1: Create ReportResource

Create `app/Http/Resources/ReportResource.php`. Reports are aggregate data,
not a model — this resource just wraps the response envelope.

```php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // $this->resource is the array returned by the controller
        return [
            'report_type' => $this['report_type'],
            'date_from' => $this['date_from'],
            'date_to' => $this['date_to'],
            'unit_id' => $this['unit_id'],
            'total' => $this['total'],
            'by_day' => $this['by_day'],
            'by_unit' => $this['by_unit'],
        ];
    }
}
```

**Verify**: `vendor/bin/pint app/Http/Resources/ReportResource.php`

### Step 2: Create ReportController

Create `app/Http/Controllers/Api/ReportController.php`.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\{Ticket, Todo, ActivityLog, User, Person};
use App\Services\AccessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class ReportController extends Controller
{
    public function index(Request $request): ReportResource
    {
        $request->validate([
            'report_type' => 'required|in:tickets,todos,users,persons',
            'date_from' => 'nullable|string|date_format:Y/m/d',
            'date_to' => 'nullable|string|date_format:Y/m/d',
            'unit_id' => 'nullable|exists:units,id',
        ]);

        $reportType = $request->input('report_type', 'tickets');
        $dateFrom = $request->input('date_from', Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d'));
        $dateTo = $request->input('date_to', Jalalian::fromCarbon(now())->format('Y/m/d'));
        $unitId = $request->input('unit_id');

        $from = $this->parseJalaliDate($dateFrom);
        $to = $this->parseJalaliDate($dateTo, endOfDay: true);
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = match ($reportType) {
            'tickets' => Ticket::whereIn('unit_id', $accessibleIds),
            'todos' => Todo::whereIn('unit_id', $accessibleIds),
            'users' => User::query(),
            'persons' => Person::whereIn('u_id', $accessibleIds),
        };

        if ($from) $query->where('created_at', '>=', $from);
        if ($to) $query->where('created_at', '<=', $to);

        if ($unitId) {
            $unitColumn = $reportType === 'persons' ? 'u_id' : 'unit_id';
            $query->where($unitColumn, $unitId);
        }

        $total = (clone $query)->count();

        $byDay = (clone $query)
            ->selectRaw("date(created_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn($row) => [
                'day' => Jalalian::fromCarbon(Carbon::parse($row->day))->format('Y/m/d'),
                'count' => (int) $row->count,
            ])
            ->values()
            ->toArray();

        $byUnit = null;
        if (in_array($reportType, ['tickets', 'todos'])) {
            $byUnit = (clone $query)
                ->selectRaw('unit_id, count(*) as count')
                ->groupBy('unit_id')
                ->orderByDesc('count')
                ->get()
                ->map(fn($row) => [
                    'unit_id' => $row->unit_id,
                    'count' => (int) $row->count,
                ])
                ->toArray();
        }

        return new ReportResource([
            'report_type' => $reportType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'unit_id' => $unitId,
            'total' => $total,
            'by_day' => $byDay,
            'by_unit' => $byUnit,
        ]);
    }

    private function parseJalaliDate(string $date, bool $endOfDay = false): ?Carbon
    {
        try {
            $carbon = Jalalian::fromFormat('Y/m/d', $date)->toCarbon();
            return $endOfDay ? $carbon->endOfDay() : $carbon->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
```

**Verify**: `vendor/bin.pint app/Http/Controllers/Api/ReportController.php`

### Step 3: Register routes

In `routes/api.php` inside the `auth:sanctum` group (after todo routes or in
its own group), add:
```php
Route::get('/reports', [ReportController::class, 'index']);
```

**Verify**: `php artisan route:list --path=api/reports`

### Step 4: Write Pest tests

Create `tests/Feature/ReportApiTest.php`.

Setup pattern: same user+unit+Session from `TodoApiTest.php`.

Test cases:
- `test_unauthenticated_returns_401`
- `test_ticket_report_returns_total_and_by_day` — query params:
  `report_type=tickets&date_from=1404/01/01&date_to=1404/12/29` → 200,
  response has `total`, `by_day` (array), `by_unit` (array for tickets)
- `test_todo_report_returns_total_and_by_day`
- `test_report_with_unit_filter_respects_scope`
- `test_invalid_report_type_returns_422`
- `test_invalid_date_format_returns_422`
- `test_report_respects_accessible_unit_scope` — requesting a ticket
  report should not leak counts for inaccessible units

**Verify**: `php artisan test tests/Feature/ReportApiTest.php`

## Test plan

All test cases above. No existing report API tests. Use
`tests/Feature/TodoApiTest.php` as structural reference.

## Done criteria

- [ ] `php artisan test` exits 0
- [ ] `vendor/bin/pint` exits 0
- [ ] `php artisan route:list --path=api/reports` shows the route
- [ ] `tests/Feature/ReportApiTest.php` exists with ≥6 tests, all passing
- [ ] `by_day` dates are in Jalali `Y/m/d` format (confirmed by test assertion)
- [ ] No files outside the in-scope list are modified (`git status`)

## STOP conditions

Stop and report back if:
- The `date_format:Y/m/d` validation rule fails for valid Jalali dates
  (Laravel's built-in date validators may not handle Jalali) — check by
  running a test; if it fails, switch to `string` validation and handle
  parsing errors in the controller.
- The `report_type=persons` with `u_id` column filtering has edge cases
  (null `u_id` persons) — investigate before writing the filter logic.

## Maintenance notes

- PDF export: add `GET /api/reports/export?format=pdf` in a later plan with
  DomPDF integration.
- If Flutter needs chart-friendly flat arrays (e.g. `labels[]`, `data[]`)
  instead of `[{day, count}]`, add a `flat=true` query param later.