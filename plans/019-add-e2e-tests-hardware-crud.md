# Plan 019: Add E2E tests for hardware CRUD lifecycle

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- tests/e2e/ resources/views/livewire/hardware/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `5f9c24e`, 2026-09-12

## Why this matters

Hardware is the most-used feature in the dashboard (66 changes in `index.blade.php`, the largest component in the project). The `tests/e2e/hardware/` directory exists but is empty — there are no E2E tests for the create, edit, or delete lifecycle. Other features (users, personnel, tickets) have E2E CRUD tests. Without hardware E2E tests, regressions in the most complex Livewire component go undetected.

## Current state

### Test infrastructure

- **Test framework**: Playwright (TypeScript)
- **Fixtures file**: `tests/e2e/shared/fixtures.ts` — exports `login`, `logout`, `waitForLivewire`, `waitForSearchResults`, `waitForToast`, `TEST_USER`
- **Existing patterns**: `tests/e2e/users/crud.spec.ts` is the best structural reference — it tests create modal, validation, edit, delete with dialog handling, soft-delete/restore
- **Test credentials**: admin user `4411015056` / `12345678`
- **E2E directory**: `tests/e2e/hardware/` exists but contains zero spec files

### Hardware Livewire component (at `resources/views/livewire/hardware/index.blade.php`)

Key UI elements for the CRUD lifecycle:

**Create flow**:
1. Button: `wire:click="startCreate"` — opens create form (in `_form-create.blade.php`)
2. Person search: `x-input wire:model.live="n_code"` — auto-searches by n_code, shows dropdown of results
3. `wire:click="selectPerson('{{ $pr['n_code'] }}', '{{ $pr['name'] }}')"` — selects a person
4. Required fields: `n_code` (person), `pc_name` (device name)
5. Optional fields: type, os, ip_valid, ip_local, mac, net_type, switch, port, vlan, motherboard, cpu, ram, hdd, comments, mark, clean_at
6. Submit: inside a `<x-form wire:submit="createHardware">`, submit button text "ذخیره" (save)

**Edit flow**:
1. Button: `wire:click="editHardware({{ $hw['id'] }})"` — opens edit modal (in `_form-edit.blade.php`)
2. Same form fields pre-filled
3. Submit: `<x-form wire:submit="updateHardware">`, button "بروزرسانی"

**Delete flow**:
1. Button: `wire:click="delete({{ $hw['id'] }})"` with `wire:confirm` (native browser dialog)
2. Soft-delete: record moves to trash
3. Trash view: filter tab "trash" shows deleted records

**Create form partial** (`_form-create.blade.php`):
```blade
@if($showForm && !$editingId)
    <div class="mb-4 p-4 bg-base-200 rounded-lg">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <x-input wire:model.live="n_code" label="کد ملی / نام پرسنل" />
            <x-input wire:model="pc_name" label="نام دستگاه" required />
            <!-- ... more fields ... -->
        </div>
    </div>
@endif
```

**Delete with trash/restore** (lines 361–395 in `index.blade.php`):
```php
public function delete(Hardware $hardware): void
{
    $hardware->load('person');
    // ... access check ...
    $hardware->delete();
    $this->success("سخت‌افزار {$hardware->pc_name} به سطل زباله منتقل شد", ...);
}
```

Trash filter and restore buttons also exist in the component.

### Data constraints for tests

- The test user must have a person with a valid `n_code` in an accessible unit
- Hardware records created during tests should be cleaned up (or use a unique identifier for easy identification and removal)
- The `n_code` field searches `persons` table within accessible units

## Commands you will need

| Purpose            | Command                              | Expected on success               |
|--------------------|--------------------------------------|-----------------------------------|
| Run hardware E2E   | `npx playwright test tests/e2e/hardware/crud.spec.ts` | all tests pass         |
| List E2E files     | `find tests/e2e/hardware -name "*.spec.ts"` | shows crud.spec.ts       |
| Check playwright    | `npx playwright --version`           | version number                    |

## Scope

**In scope** (the only files you should create/modify):
- `tests/e2e/hardware/crud.spec.ts` (create)

**Out of scope**:
- `tests/e2e/hardware/list-filters.spec.ts` — already planned/separate
- `resources/views/livewire/hardware/` — no UI changes
- API hardware tests — separate scope
- Test database setup — assumed to exist via project's E2E test infrastructure

## Git workflow

- Branch: `celin`
- Commit message: `test(e2e): add hardware CRUD lifecycle tests`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Create the spec file with imports and structure

Create `tests/e2e/hardware/crud.spec.ts` following the pattern from `tests/e2e/users/crud.spec.ts`:

```typescript
import { test, expect, login } from '../shared/fixtures';

/**
 * Plan 019 — Hardware CRUD lifecycle tests.
 *
 * Probed DOM facts:
 * - /hardware: table with hardware records
 * - Create: button[wire:click="startCreate"] → inline form appears
 * - Person search: input[wire:model.live="n_code"] (debounced, auto-searches)
 * - Person dropdown: div with wire:click="selectPerson('n_code', 'name')"
 * - Required fields: n_code (person), pc_name
 * - Submit: x-form wire:submit="createHardware" → button "ذخیره"
 * - Edit: button[wire:click="editHardware({id})"] → edit modal
 * - Update submit: x-form wire:submit="updateHardware" → button "بروزرسانی"
 * - Delete: button[wire:click="delete({id})"] with wire:confirm (native dialog)
 * - Toast success: contains "ایجاد شد" / "بروزرسانی شد" / "منتقل شد"
 */
```

### Step 2: Implement beforeEach — login and navigate

```typescript
test.describe('hardware CRUD lifecycle', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/hardware');
    await page.waitForLoadState('networkidle');
  });
```

### Step 3: Test — create hardware record

The test must:
1. Click the "create" button (`button[wire\\:click="startCreate"]`)
2. Wait for the form to appear (look for `کد ملی / نام پرسنل` label or the n_code input)
3. Type a known n_code into the person search field
4. Wait for the dropdown to appear and select the first result
5. Fill in `pc_name` with a unique test name (e.g., `E2E-TEST-PC-${Date.now()}`)
6. Click the submit button ("ذخیره")
7. Wait for the toast with "ایجاد شد"
8. Verify the new PC name appears in the table

Key selectors:
- Create button: `button[wire\\:click="startCreate"]`
- n_code input: `input[wire\\:model\\.live="n_code"]` (inside `_form-create.blade.php`)
- pc_name input: `input[wire\\:model="pc_name"]`
- Submit: within the form, button with text "ذخیره"
- Toast: `.toast:has-text("ایجاد شد")`

```typescript
  test('create a hardware record', async ({ page }) => {
    const pcName = `E2E-TEST-PC-${Date.now()}`;

    // Open create form
    await page.locator('button[wire\\:click="startCreate"]').click();
    await expect(page.locator('text=کد ملی / نام پرسنل')).toBeVisible({ timeout: 5000 });

    // Search for a person by n_code (use the test admin's n_code)
    const nCodeInput = page.locator('input[wire\\:model\\.live="n_code"]');
    await nCodeInput.fill('4411015056');
    // Wait for dropdown results
    await page.waitForFunction(() => {
      const dropdowns = document.querySelectorAll('[wire\\:click^="selectPerson"]');
      return dropdowns.length > 0;
    }, { timeout: 10000 });
    // Click the first person result
    await page.locator('[wire\\:click^="selectPerson"]').first().click();
    // Wait for Livewire to process the selection
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });

    // Fill PC name
    await page.locator('input[wire\\:model="pc_name"]').fill(pcName);

    // Submit
    await page.getByRole('button', { name: 'ذخیره' }).click();

    // Wait for success toast
    await expect(page.locator('.toast')).toContainText('ایجاد شد', { timeout: 10000 });

    // Verify it appears in the list (search for it)
    const searchInput = page.locator('input[wire\\:model\\.live\\.debounce\\.300ms="search"]');
    await searchInput.fill(pcName);
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });
    await expect(page.locator('table tbody')).toContainText(pcName);
  });
```

### Step 4: Test — edit hardware record

After creating, edit the record:
1. Search for the created PC name
2. Click the edit button on the first matching row
3. Verify the edit modal opens with pre-filled values
4. Change a field (e.g., `os`)
5. Submit
6. Verify the toast and updated value

```typescript
  test('edit a hardware record', async ({ page }) => {
    const pcName = `E2E-TEST-EDIT-${Date.now()}`;
    // First create a record to edit (abbreviated — reuse create logic)
    // ... (create setup) ...

    // Search for the record
    const searchInput = page.locator('input[wire\\:model\\.live\\.debounce\\.300ms="search"]');
    await searchInput.fill(pcName);
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });

    // Click edit button
    await page.locator('button[wire\\:click^="editHardware("]').first().click();
    await expect(page.locator('text=بروزرسانی سخت‌افزار')).toBeVisible({ timeout: 10000 });

    // Change OS field
    const osInput = page.locator('input[wire\\:model="os"]');
    await osInput.clear();
    await osInput.fill('Windows 11 E2E');

    // Submit update
    await page.getByRole('button', { name: 'بروزرسانی' }).click();
    await expect(page.locator('.toast')).toContainText('بروزرسانی شد', { timeout: 10000 });
  });
```

### Step 5: Test — soft-delete and verify in trash

1. Search for a test record
2. Accept the native confirm dialog
3. Verify the record disappears from the active list
4. Switch to trash view
5. Verify the record appears in trash

```typescript
  test('soft-delete a hardware record and verify in trash', async ({ page }) => {
    const pcName = `E2E-TEST-DEL-${Date.now()}`;
    // ... create setup ...

    // Search for the record
    const searchInput = page.locator('input[wire\\:model\\.live\\.debounce\\.300ms="search"]');
    await searchInput.fill(pcName);
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });

    // Accept confirm dialog
    page.on('dialog', (dialog) => dialog.accept());
    await page.locator('button[wire\\:click^="delete("]').first().click();
    await page.waitForFunction(() => !document.querySelector('.wire-loading'), { timeout: 10000 });

    // Record should be gone from active list
    await expect(page.locator('table tbody')).not.toContainText(pcName);

    // Switch to trash filter
    // (Need to find the trash tab/button — inspect the filter UI)
    // The trash tab likely uses a button or link with "سطل زباله" or filter state
    // ... navigate to trash view ...

    // Verify record appears in trash
    // await expect(page.locator('table tbody')).toContainText(pcName);

    // Restore the record (to keep test environment clean)
    // ... restore action ...
  });
```

### Step 6: Test — create validation error (missing required fields)

1. Open create form
2. Click submit without filling required fields
3. Verify validation error appears

```typescript
  test('create without required fields shows validation error', async ({ page }) => {
    await page.locator('button[wire\\:click="startCreate"]').click();
    await expect(page.locator('text=کد ملی / نام پرسنل')).toBeVisible({ timeout: 5000 });

    // Submit without filling anything
    await page.getByRole('button', { name: 'ذخیره' }).click();
    await page.waitForTimeout(1200);

    // Should show validation errors (required fields)
    await expect(page.locator('table tbody')).toContainText(/.*/);
  });
```

### Step 7: Cleanup helper and final assertions

Add a `test.afterEach` or helper function that cleans up any test records created during the test run. Alternatively, use a unique prefix (`E2E-TEST-`) so leftover records are identifiable.

**Verify**: `npx playwright test tests/e2e/hardware/crud.spec.ts --list` → shows all test names.

## Test plan

New test file: `tests/e2e/hardware/crud.spec.ts`

Test cases:
1. **create a hardware record** — create flow end-to-end, verify in list
2. **edit a hardware record** — edit flow, verify field change persists
3. **soft-delete and verify in trash** — delete, verify removed, check trash
4. **create without required fields** — validation error path

Pattern reference: `tests/e2e/users/crud.spec.ts` (login helper, dialog handling, Livewire wait patterns).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `tests/e2e/hardware/crud.spec.ts` exists and is valid TypeScript
- [ ] `npx playwright test tests/e2e/hardware/crud.spec.ts --list` exits 0 and lists ≥ 3 tests
- [ ] `npx playwright test tests/e2e/hardware/crud.spec.ts` passes (all tests green)
- [ ] No files outside `tests/e2e/hardware/crud.spec.ts` are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- `npx playwright` is not available or not configured for this project.
- The hardware create form UI has changed (selectors like `startCreate`, `createHardware`, `pc_name` don't match).
- Test database doesn't have the required seed data (person with n_code `4411015056` in an accessible unit).
- Playwright tests require a running server and the server cannot be started.

## Maintenance notes

- Test records use the `E2E-TEST-` prefix for easy identification. If tests fail mid-run, leftover records can be found and deleted manually.
- If the hardware form adds new required fields, update the create test.
- The trash/restore test flow depends on the exact UI for the trash filter — the implementer may need to inspect the trash tab selector at execution time.
- These tests mutate the database (create/edit/delete hardware records). If the test suite runs in parallel, consider using a unique timestamp suffix for pc_name to avoid collisions.
