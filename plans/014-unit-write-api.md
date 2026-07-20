# Plan 014: Add Unit write API — create, update, delete

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat ac23e15..HEAD -- app/Models/Unit.php app/Http/Controllers/Api/UnitController.php routes/api.php`
> If any in-scope file changed, compare "Current state" excerpts before
> proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED — write operations on organizational hierarchy
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `ac23e15`, 2025-07-20
- **Issue**: —

## Why this matters

`GET /api/unit` already scopes results to accessible units and works as a
read endpoint for maps. Flutter mobile app users who manage units need
create/update/delete. Currently all unit management is web-only.

## Current state

**`app/Models/Unit.php`** — fillable fields:
```php
protected $fillable = [
    'name', 'description', 'region_id', 'parent_id',
    'unit_type_id', 'boundary_id', 'lat', 'lng',
];
```
Relations: `parent`, `children`, `childrenRecursive`, `unitType`, `region`,
`boundary`, `assignedUsers`. No `is_active`, `can_receive_tickets` in
fillable — check the migration before including these in validation.

**`app/Http/Controllers/Api/UnitController.php`** — current state is a single
`__invoke` returning a paginated list. No other methods.

**`routes/api.php:35`** — `Route::middleware('auth:sanctum')->get('/unit', UnitController::class);`

Repo conventions: Pest, `RefreshDatabase`, response shape for mutations:
`{ success: true, data: <resource> }`. Access control via
`app(SccessService::class)->accessibleUnitIds()`.

**Out of scope in current codebase**: `unit_type_relationships` validation
(parent/child unit type rules) — complex, deferred to a later plan.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Tests | `php artisan test` | exit 0, all pass |
| Lint | `vendor/bin/pint` | exit 0 |

## Scope

**In scope**:
- `app/Http/Controllers/Api/UnitController.php` — add store/update/destroy
- `app/Http/Resources/UnitResource.php` — create
- `routes/api.php` — add write routes
- `tests/Feature/UnitApiTest.php` — create

**Out of scope**:
- `unit_type_relationships` validation (allowed parent/child type rules)
- Boundary (GIS polygon) upload or editing — complex, deferred
- `is_active` / `can_receive_tickets` toggling (verify these columns exist
  in the migration before including)
- Moving a unit in the hierarchy (re-parenting) — complex, deferred
- Any change to web Livewire components

## Git workflow

- Branch: `feature/unit-write-api`
- Commit style: `feat: add unit write API` (+ per-step commits as needed)
- Do NOT push or open a PR unless the operator instructed it

## Steps

### Step 1: Create UnitResource

Create `app/Http/Resources/UnitResource.php` with these fields:
```php
'id', 'name', 'description', 'lat', 'lng',
'parent_id', 'unit_type_id', 'region_id', 'boundary_id',
'created_at', 'updated_at',
```
Include nested `parent` (id + name via `whenLoaded`), `unitType`
(id + name), `region` (id + name). Match `TodoResource.php` pattern.

**Verify**: `vendor/bin/pint app/Http/Resources/UnitResource.php`

### Step 2: Add UnitController methods

Add to `app/Http/Controllers/Api/UnitController.php` (rename class file to
match if needed):

```php
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name' => 'required|string|min:2|max:255',
        'description' => 'nullable|string',
        'region_id' => 'nullable|exists:regions,id',
        'parent_id' => 'nullable|exists:units,id',
        'unit_type_id' => 'nullable|exists:unit_types,id',
        'lat' => 'nullable|numeric',
        'lng' => 'nullable|numeric',
    ]);

    $unit = Unit::create($validated);
    return response()->json(['success' => true, 'data' => new UnitResource($unit)], 201);
}

public function show(Unit $unit): JsonResponse
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    if (!in_array($unit->id, $accessibleIds)) {
        return response()->json(['message' => 'Unauthorized.'], 403);
    }
    return response()->json([
        'success' => true,
        'data' => new UnitResource($unit->load(['parent', 'unitType', 'region'])),
    ]);
}

public function update(Request $request, Unit $unit): JsonResponse
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    if (!in_array($unit->id, $accessibleIds)) {
        return response()->json(['message' => 'Unauthorized.'], 403);
    }

    $validated = $request->validate([
        'name' => 'sometimes|required|string|min:2|max:255',
        'description' => 'nullable|string',
        'region_id' => 'nullable|exists:regions,id',
        'parent_id' => 'nullable|exists:units,id',
        'unit_type_id' => 'nullable|exists:unit_types,id',
        'lat' => 'nullable|numeric',
        'lng' => 'nullable|numeric',
    ]);

    $unit->update($validated);
    return response()->json([
        'success' => true,
        'data' => new UnitResource($unit),
    ]);
}

public function destroy(Unit $unit): JsonResponse
{
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    if (!in_array($unit->id, $accessibleIds)) {
        return response()->json(['message' => 'Unauthorized.'], 403);
    }

    // Check for children — deleting a unit with children is probably a mistake
    if ($unit->children()->exists()) {
        return response()->json([
            'message' => 'Cannot delete unit with child units.',
        ], 422);
    }

    $unit->delete();
    return response()->json(['success' => true, 'message' => 'Unit deleted.']);
}
```

Add required imports:
```php
use App\Http\Resources\UnitResource;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
```

**Verify**: `vendor/bin/pint app/Http/Controllers/Api/UnitController.php`

### Step 3: Add routes in `routes/api.php`

After the existing `Route::get('/unit', ...)` line, add:
```php
Route::get('/units/{unit}', [UnitController::class, 'show']);
Route::post('/units', [UnitController::class, 'store']);
Route::put('/units/{unit}', [UnitController::class, 'update']);
Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
```
Note: rename the list route to `/units` for consistency (`GET /api/units`).

**Verify**: `php artisan route:list --path=api/units`

### Step 4: Write Pest tests

Create `tests/Feature/UnitApiTest.php` following `TodoApiTest.php` pattern.

Test cases:
- `test_unauthenticated_returns_401`
- `test_create_unit` — 201, response has id + name
- `test_create_unit_requires_name`
- `test_update_unit` — 200, name changed
- `test_delete_unit_without_children` — 200, deleted
- `test_delete_unit_with_children_returns_422`
- `test_show_unit` — 200 with UnitResource shape
- `test_show_inaccessible_unit_returns_403`
- `test_update_inaccessible_unit_returns_403`
- `test_delete_inaccessible_unit_returns_403`
- `test_update_non_existent_unit_returns_404`

Setup: same user+unit+Session pattern as `TodoApiTest.php`.

**Verify**: `php artisan test tests/Feature/UnitApiTest.php`

## Test plan

All test cases above. No existing tests for Unit API — all new. Use
`tests/Feature/TodoApiTest.php` as structural reference.

## Done criteria

- [ ] `php artisan test` exits 0
- [ ] `vendor/bin/pint` exits 0
- [ ] `php artisan route:list --path=api/units` shows ≥5 routes (GET list,
  GET show, POST store, PUT update, DELETE destroy)
- [ ] `tests/Feature/UnitApiTest.php` exists with ≥10 tests, all passing
- [ ] No files outside the in-scope list are modified (`git status`)

## STOP conditions

Stop and report back if:
- `is_active` or `can_receive_tickets` columns exist in the units table
  and should be included — check the migration before proceeding and
  report which fields are available.
- `unit_type_relationships` validation is required immediately (defer to a
  later plan in that case).
- The `parent_id` delete rule in the FK constraint (restrict) would cause
  DB errors — verify this before writing the delete logic.

## Maintenance notes

- `boundary_id` upload/creation not covered — separate plan needed for GIS
  boundary management.
- If the Flutter app needs to assign users to units, add a separate
  `POST /api/units/{unit}/users` endpoint later.