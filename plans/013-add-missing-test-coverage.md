# Plan 013: Add missing test coverage

> **Executor instructions**: Follow this plan step by step.

## Status
- **Priority**: P3
- **Effort**: H
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: 2026-09-19

## Why this matters
Todo Livewire component (calendar, CRUD) has zero tests. Only 1 e2e test file exists. Hardware tests dominate coverage while other areas are under-tested.

## Current state
- `tests/Feature/`: 160 files, concentrated in Hardware
- `tests/e2e/`: only `create-pwd-user.php`
- `resources/views/livewire/todo/todo.blade.php`: full calendar + CRUD, no tests
- No tests for: Settings, Profile, Reports (most), IT monitoring components

## Steps

### Step 1: Create Todo Livewire test
Create `tests/Feature/TodoLivewireTest.php`:
- test mount loads calendar events
- test create todo via modal
- test edit todo
- test toggle completion
- test unit scoping

### Step 2: Add e2e test for login→dashboard flow
Create `tests/e2e/auth.spec.ts`:
- test login with valid credentials
- test redirect to dashboard
- test logout

### Step 3: Add e2e test for hardware CRUD
Create `tests/e2e/hardware.spec.ts`:
- test hardware list loads
- test create hardware
- test edit hardware
- test delete hardware

### Step 4: Run tests
**Verify**: `cd /home/runner/h-dashboard && composer test -- --filter=TodoLivewireTest 2>&1 | tail -10` → passes

## Done criteria
- [ ] TodoLivewireTest.php exists and passes
- [ ] At least 1 new e2e spec file
- [ ] All tests pass
