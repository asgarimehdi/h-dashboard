# Plan 007: Missing user_id in Todo creation — add ownership tracking

> Written against commit: `31e2a7c` (beta/sydney)
> Category: Correctness | Effort: S | Impact: Medium

## Problem

`TodoController::store()` fetches the authenticated user (`$user = $request->user()`) at line 49 but never passes `user_id` to `Todo::create()` at lines 59-65. The Livewire `todo.todo` component has the same bug — `Todo::updateOrCreate()` at line 220-229 omits `user_id`.

The `todos` table has a `user_id` column (migration `2026_08_02_234519_add_user_id_to_todos_table.php`, nullable FK), the Todo model lists `user_id` in `$fillable` and defines a `user()` BelongsTo relationship — but nothing ever populates it.

**Consequence:** All API-created and Livewire-created todos have `user_id = null`. There is no way to know who created a todo. The `TodoResource` API transformer also omits `user_id` from its output, so the Flutter app cannot display todo ownership.

### Evidence

- `app/Http/Controllers/Api/TodoController.php:49` — `$user = $request->user()` fetched but unused
- `app/Http/Controllers/Api/TodoController.php:59-65` — `Todo::create([...])` missing `user_id`
- `resources/views/livewire/todo/todo.blade.php:220-229` — `Todo::updateOrCreate(...)` missing `user_id`
- `app/Models/Todo.php:23` — `user_id` is in `$fillable`
- `app/Models/Todo.php:42-45` — `user()` BelongsTo relationship defined but never used
- `app/Http/Resources/TodoResource.php:12-26` — API response excludes `user_id`
- `database/migrations/2026_08_02_234519_add_user_id_to_todos_table.php:15` — column exists

**Contrast with correct pattern** (Ticket create):
- `resources/views/livewire/tickets/⚡create.blade.php:120` — `'user_id' => auth()->id()`

## Solution

Set `user_id` in both the API controller and the Livewire component. Add `user_id` to the API resource output.

### Before (TodoController.php:59-65)

```php
$todo = Todo::create([
    'title' => $validated['title'],
    'start_at' => $validated['start_at'],
    'end_at' => $validated['end_at'] ?? null,
    'is_completed' => $validated['is_completed'] ?? false,
    'unit_id' => $unitId,
]);
```

### After

```php
$todo = Todo::create([
    'title' => $validated['title'],
    'start_at' => $validated['start_at'],
    'end_at' => $validated['end_at'] ?? null,
    'is_completed' => $validated['is_completed'] ?? false,
    'unit_id' => $unitId,
    'user_id' => $user->id,
]);
```

### Before (todo.blade.php:220-229)

```php
Todo::updateOrCreate(
    ['id' => $this->editingId],
    [
        'title' => $this->title,
        'start_at' => $startMildadi,
        'end_at' => $endMildadi,
        'is_completed' => $this->is_completed,
        'unit_id' => $this->unit_id,
    ]
);
```

### After

```php
Todo::updateOrCreate(
    ['id' => $this->editingId],
    [
        'title' => $this->title,
        'start_at' => $startMildadi,
        'end_at' => $endMildadi,
        'is_completed' => $this->is_completed,
        'unit_id' => $this->unit_id,
        'user_id' => auth()->id(),
    ]
);
```

### TodoResource — expose user_id

```php
// Add after 'unit_id':
'user_id' => $this->user_id,
'user' => $this->whenLoaded('user', fn () => [
    'id' => $this->user->id,
    'name' => $this->user->name,
]),
```

## Files in Scope

- `app/Http/Controllers/Api/TodoController.php`
- `resources/views/livewire/todo/todo.blade.php`
- `app/Http/Resources/TodoResource.php`
- `tests/Feature/TodoApiTest.php`
- `tests/Feature/TodoLivewireTest.php`

## Files Out of Scope

- `app/Models/Todo.php` (no change — `user_id` already in `$fillable` and relationship exists)
- `database/migrations/2026_08_02_234519_add_user_id_to_todos_table.php` (column already exists)

## Steps

### Step 1: TodoController::store — set user_id

1. In `app/Http/Controllers/Api/TodoController.php`, add `'user_id' => $user->id` to the `Todo::create()` array (after line 64)
2. Verify: `grep -n "user_id" app/Http/Controllers/Api/TodoController.php`

### Step 2: Livewire todo.todo — set user_id

1. In `resources/views/livewire/todo/todo.blade.php`, add `'user_id' => auth()->id()` to the `Todo::updateOrCreate()` array (after line 228)
2. Verify: `grep -n "user_id" resources/views/livewire/todo/todo.blade.php`

### Step 3: TodoResource — expose user_id in API response

1. In `app/Http/Resources/TodoResource.php`, add `'user_id' => $this->user_id` to the `toArray()` return array
2. Optionally add a loaded `user` relationship with `id` and `name`
3. Verify: `grep -n "user_id" app/Http/Resources/TodoResource.php`

### Step 4: Update existing tests

1. In `tests/Feature/TodoApiTest.php`, update `test_user_can_create_todo_in_accessible_unit` to assert `user_id` is set:
   ```php
   $this->assertDatabaseHas('todos', [
       'title' => 'تست تسک جدید',
       'unit_id' => $unit->id,
       'user_id' => $user->id,
   ]);
   ```
2. Add a new test:
   ```php
   public function test_created_todo_belongs_to_authenticated_user(): void
   {
       ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

       $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
           'title' => 'Ownership Test',
           'start_at' => '2026-07-15 10:00:00',
       ]);

       $response->assertStatus(201);
       $this->assertDatabaseHas('todos', [
           'title' => 'Ownership Test',
           'user_id' => $user->id,
       ]);
   }
   ```
3. In `tests/Feature/TodoLivewireTest.php`, update `test('can create a todo', ...)` to assert user_id:
   ```php
   $this->assertDatabaseHas('todos', [
       'title' => 'وظیفه تستی',
       'user_id' => $this->user->id,
   ]);
   ```

### Step 5: Verify

```bash
composer pint       # Format PHP code
composer phpstan    # Static analysis
composer test       # All 1352+ tests pass
```

## Test Plan

1. Existing `TodoApiTest` should pass after updating assertions
2. Existing `TodoLivewireTest` should pass after updating assertions
3. New test: `test_created_todo_belongs_to_authenticated_user` verifies user_id on store
4. Verify API response now includes `user_id` in JSON output
5. `grep -rn "'user_id'" app/Http/Controllers/Api/TodoController.php` returns 1 result

## Maintenance Note

The `user_id` column is nullable (migration uses `->nullable()`). Existing todos in production will have `user_id = null`. Consider a one-time migration or backfill command to set `user_id` for existing todos based on any audit trail or timestamps — but that's out of scope for this plan. The nullable FK ensures backward compatibility.

## Done Criteria

- [ ] `TodoController::store()` sets `user_id` from `$request->user()->id`
- [ ] Livewire `todo.todo` `save()` sets `user_id` from `auth()->id()`
- [ ] `TodoResource` exposes `user_id` in API response
- [ ] `test_user_can_create_todo_in_accessible_unit` asserts `user_id` in DB
- [ ] New ownership test passes
- [ ] `composer test` — all tests pass
- [ ] `composer phpstan` — no new errors
- [ ] `composer pint` — code formatted
