# Plan 013: Add missing test coverage (Pest)

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: H
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: 2026-09-19 (v2, AGENTS.md test conventions)

## Why this matters
Todo Livewire component (calendar, CRUD) has zero tests. Only 1 e2e test file exists. Hardware tests dominate coverage while other areas are under-tested.

## AGENTS.md test conventions
- **Test runner**: Pest (`composer test`)
- **Single file**: `XDEBUG_MODE=off php artisan test tests/Feature/XxxTest.php`
- **Test database**: `h_dashboard_test` (PostgreSQL, separate from dev)
- **Pre-reqs**: `docker compose -f docker-compose-pgsql-.yml up -d`, `php artisan config:clear && route:clear`
- **Factories**: Only `UserFactory` exists; other models use seeders
- **Postgres sequence**: after seeding with explicit IDs, `SELECT setval(...)` to avoid dup keys
- **Livewire testing**: use `Livewire::test('component.dotname')` — components are single-file anonymous classes

## Current state
- `tests/Feature/`: 160+ files, concentrated in Hardware
- `tests/e2e/`: only `create-pwd-user.php` (from PR description; actual count may differ)
- Zero tests for: Todo Livewire, Settings, Profile, most Reports, IT monitoring

## Steps

### Step 1: Create Todo Livewire test
Create `tests/Feature/TodoLivewireTest.php` (Pest):

```php
<?php

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('mounts and loads calendar events', function () {
    $user = User::factory()->create();
    actingAs($user);

    Livewire::test('todo.todo')
        ->assertStatus(200);
});

it('can create a todo', function () {
    $user = User::factory()->create();
    actingAs($user);

    Livewire::test('todo.todo')
        ->set('title', 'Test todo')
        ->call('createTodo')
        ->assertHasNoErrors();
});

it('can toggle todo completion', function () {
    // Seed a todo, then toggle
});

it('scopes todos to current unit', function () {
    // Verify unit-based filtering
});
```

### Step 2: Add e2e test for login→dashboard flow
Create `tests/e2e/auth.spec.ts`:

```typescript
import { test, expect } from '@playwright/test';
import { login, logout, waitForLivewire } from './shared/fixtures';

test('login redirects to dashboard', async ({ page }) => {
    await login(page);
    await expect(page).toHaveURL(/\/dashboard/);
});

test('logout redirects to login', async ({ page }) => {
    await login(page);
    await logout(page);
    await expect(page).toHaveURL(/\/login/);
});
```

### Step 3: Add e2e test for hardware CRUD
Create `tests/e2e/hardware.spec.ts`:
- test hardware list loads
- test create hardware via modal
- test edit hardware
- test delete hardware with confirmation

### Step 4: Run tests
```bash
php artisan config:clear && route:clear
composer test -- --filter=TodoLivewireTest
# → passes
npx playwright test tests/e2e/auth.spec.ts
# → passes
```

## Done criteria
- [ ] TodoLivewireTest.php exists and passes
- [ ] At least 1 new e2e spec file
- [ ] `composer test` passes (all existing tests)
