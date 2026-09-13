# Plan 018: Extract unit-to-user ID helper + standardize debounce timings

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- app/Services/AccessService.php resources/views/livewire/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `5f9c24e`, 2026-09-12

## ⚠️ TL;DR فارسی

**مشکل:** query user ~۶ بار تکرار. debounce 150/300/500ms ناسازگار.

**⚠️ تأیید شد:** همه ۲۸ binding search/filter هستن — هیچ regular input نیست.
**۲۲ تا bare debounce (150ms)** → همه search: hardware, roles, tickets, kargozini, units, permissions
**۴ تا explicit 300ms** → maps, ticket create, org-chart, global search
**۲ تا explicit 500ms** → person_search (heavy), units/chart
**نتیجه:** 300ms برای همه bare debounce مناسبه. 500ms برای person_search منطقیه.

**ریسک:** 🟢 کم

---

## Why this matters

The query `User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))->pluck('id')` is copy-pasted in at least 10 locations across blade components and controllers. This means every bug fix or optimization to this query must be applied N times. Additionally, `wire:model.live.debounce` (default 150ms) is used in ~22 components while 4 use explicit 300ms and 2 use 500ms — the inconsistency creates unpredictable search responsiveness.

## Current state

### Duplicated user-ID-by-unit query

The following files contain `whereHas('person', fn($q) => $q->whereIn('u_id', ...`:

| File | Lines | Pattern |
|------|-------|---------|
| `resources/views/livewire/search/index.blade.php` | 32 | `User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))->pluck('id')->toArray()` |
| `resources/views/livewire/tools/tools.blade.php` | 22, 63, 76, 87 | Same pattern (4 occurrences!) |
| `resources/views/livewire/dashboard.blade.php` | 109, 219 | Same pattern (2 occurrences) |
| `resources/views/livewire/activity-log/index.blade.php` | 58 | Same pattern |
| `app/Http/Controllers/Api/HardwareExportController.php` | 31 | Similar (on Person query, not User) |
| `app/Http/Controllers/Api/HardwareController.php` | 116, 253, 285, 328 | `whereIn('persons.u_id', $accessibleIds)` (Person-level filtering, not User) |
| `app/Http/Controllers/Api/HrStatsController.php` | 71, 143 | Person-level |
| `app/Http/Controllers/Api/HrAnalyticsController.php` | 55, 124, 198 | Person-level |
| `app/Http/Controllers/Api/GisController.php` | 167, 337 | Person-level |
| `app/Http/Controllers/Api/PersonController.php` | 19 | Person-level |

The **User ID** variant (which is the duplicated helper target) appears in 5 blade files: `search`, `tools` (4×), `dashboard` (2×), `activity-log`.

The **Person-level** `whereIn('u_id', ...)` pattern is used in API controllers to filter Person queries by accessible units — this is a different operation and is NOT part of this plan's helper extraction.

### Current `AccessService` (at `app/Services/AccessService.php`)

```php
class AccessService
{
    public function accessibleUnitIds(?User $user = null): array { ... }
    public function clearCache(?User $user = null): void { ... }
    public function clearAllCaches(): void { ... }
}
```

No `accessibleUserIds()` method exists.

### Debounce timings

| Timing | Count | Files |
|--------|-------|-------|
| `debounce` (default 150ms) | ~22 | hardware/_toolbar, hardware/filters (×9), roles/index, ⚡monitoring, ⚡inbox, users/index, activity-log/index, units/index, permissions/index, kargozini/estekhdam, kargozini/person, kargozini/tahsil, kargozini/radif, kargozini/semat |
| `debounce.300ms` | 4 | maps/unit, ⚡create, search/index, hr/org-chart |
| `debounce.500ms` | 2 | users/index (person_search), units/chart |

## Commands you will need

| Purpose            | Command                                                  | Expected on success                |
|--------------------|----------------------------------------------------------|------------------------------------|
| Drift check        | `git diff --stat 5f9c24e..HEAD -- app/Services/AccessService.php` | empty                    |
| Verify helper      | `grep -rn 'accessibleUserIds' app/`                      | single definition in AccessService |
| Verify callers     | `grep -rn 'accessibleUserIds' resources/`                | all 5 blade files reference it    |
| Verify debounce    | `grep -rn 'wire:model.live.debounce"' resources/views/livewire/` | zero (all explicit)    |
| Verify 300ms       | `grep -c 'debounce.300ms' resources/views/livewire/`     | ~26                                |

## Scope

**In scope** (the only files you should modify):
- `app/Services/AccessService.php` — add `accessibleUserIds()` method
- `resources/views/livewire/search/index.blade.php` — use helper, debounce
- `resources/views/livewire/tools/tools.blade.php` — use helper (4 occurrences)
- `resources/views/livewire/dashboard.blade.php` — use helper (2 occurrences)
- `resources/views/livewire/activity-log/index.blade.php` — use helper
- All blade files with `wire:model.live.debounce` (no explicit timing) — change to `debounce.300ms`

**Out of scope**:
- API controllers (HardwareController, HrStatsController, etc.) — they use Person-level filtering, not the User ID pattern
- Hardware model scopes — separate optimization
- `resources/views/op/index.php` — external dependency with its own debounce

## Git workflow

- Branch: `celin`
- Commit message: `refactor: extract accessibleUserIds() + standardize debounce to 300ms`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add `accessibleUserIds()` to AccessService

In `app/Services/AccessService.php`, add a new public method after `accessibleUnitIds()`:

```php
/**
 * IDs of users whose person belongs to an accessible unit.
 *
 * @return array<int>
 */
public function accessibleUserIds(?User $user = null): array
{
    $accessibleIds = $this->accessibleUnitIds($user);

    if (empty($accessibleIds)) {
        return [];
    }

    return User::whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds))
        ->pluck('id')
        ->toArray();
}
```

Add `use App\Models\User;` at the top if not already imported (it is already imported — the file uses `?User $user`).

**Verify**: `php artisan tinker --execute="dd(app(\App\Services\AccessService::class)->accessibleUserIds())"` → returns an array of user IDs (or empty array if no session).

### Step 2: Replace duplicated queries in blade components

Replace each occurrence of the User-ID query pattern with a call to the helper.

**`resources/views/livewire/search/index.blade.php`** (line 32):
```php
// BEFORE:
$userIds = User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))->pluck('id')->toArray();
// AFTER:
$userIds = app(AccessService::class)->accessibleUserIds();
```

**`resources/views/livewire/dashboard.blade.php`** (lines 109, 219):
Replace both occurrences:
```php
// BEFORE:
$userIds = User::whereHas('person', fn ($q) => $q->whereIn('u_id', $accessibleIds))->pluck('id')->toArray();
// AFTER:
$userIds = app(AccessService::class)->accessibleUserIds();
```

**`resources/views/livewire/tools/tools.blade.php`** (lines 22, 63, 76, 87):
Replace all 4 occurrences. Lines 63 and 76 have inline queries — adapt to use the helper:
```php
// Lines 63, 76 BEFORE:
$count = ActivityLog::whereIn('user_id', User::whereHas('person', fn($q) => $q->whereIn('u_id', app(AccessService::class)->accessibleUnitIds()))->pluck('id'))->count();
// AFTER:
$count = ActivityLog::whereIn('user_id', app(AccessService::class)->accessibleUserIds())->count();
```

Lines 22 and 87 follow the same pattern as search.

**`resources/views/livewire/activity-log/index.blade.php`** (line 58):
```php
// BEFORE:
return \App\Models\User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleUnitIds))->pluck('id');
// AFTER:
return app(AccessService::class)->accessibleUserIds();
```

**Verify**: `grep -rn "User::whereHas.*person.*whereIn.*u_id.*pluck.*id" resources/views/livewire/` → returns zero matches.

### Step 3: Standardize debounce to 300ms

In all blade files under `resources/views/livewire/`, replace bare `wire:model.live.debounce="` with `wire:model.live.debounce.300ms="`. This affects all occurrences that do NOT already have an explicit timing.

Files to change (bare debounce → 300ms):
- `hardware/_toolbar.blade.php`
- `hardware/filters.blade.php` (×9 inputs)
- `roles/index.blade.php`
- `tickets/⚡monitoring.blade.php`
- `tickets/⚡inbox.blade.php`
- `users/index.blade.php` (search input only; person_search already has 500ms — leave it)
- `activity-log/index.blade.php`
- `units/index.blade.php`
- `permissions/index.blade.php`
- `kargozini/estekhdam.blade.php`
- `kargozini/person.blade.php`
- `kargozini/tahsil.blade.php`
- `kargozini/radif.blade.php`
- `kargozini/semat.blade.php`

The 4 files already at 300ms (`maps/unit`, `⚡create`, `search/index`, `hr/org-chart`) and the 2 at 500ms (`users/index` person_search, `units/chart`) stay as-is — they have explicit timings for good reasons (person search needs more time for network round-trip; units/chart has heavy data).

**Verify**: `grep -rn 'wire:model.live.debounce="' resources/views/livewire/` → returns zero matches (no bare debounce left).

### Step 4: Final verification

**Verify**:
1. `grep -rn 'accessibleUserIds' app/Services/AccessService.php` → shows single method definition
2. `grep -rn 'accessibleUserIds' resources/views/livewire/` → shows all 5 blade files referencing it
3. `grep -rn 'User::whereHas.*person.*pluck.*id' resources/views/livewire/` → zero matches
4. `grep -rn 'wire:model.live.debounce="' resources/views/livewire/` → zero matches

## Test plan

- No new E2E tests needed — this is a refactor with no behavior change
- Existing E2E tests should pass unchanged:
  - `npx playwright test tests/e2e/search/` — search still works
  - `npx playwright test tests/e2e/dashboard/` — dashboard loads
  - `npx playwright test tests/e2e/tools/` — tools page loads
  - `npx playwright test tests/e2e/activity/` — activity log loads

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'accessibleUserIds' app/Services/AccessService.php` returns `1` (method exists)
- [ ] `grep -rn 'User::whereHas.*person.*pluck.*id' resources/views/livewire/` returns 0 matches
- [ ] `grep -rn 'wire:model.live.debounce="' resources/views/livewire/` returns 0 matches
- [ ] `grep -c 'debounce.300ms' resources/views/livewire/` returns ≥ 24
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at the locations in "Current state" doesn't match the excerpts (the codebase has drifted since this plan was written).
- A blade file uses `User::whereHas('person', ...)` for a purpose OTHER than getting user IDs (e.g., counting, checking existence) — do not blindly replace those.
- The `accessibleUserIds()` method causes a cache-invalidation issue (test by checking that switching unit context clears the user ID cache too).

## Maintenance notes

- If `Person` gains a new relationship to `Unit` (e.g., multi-unit assignment), the `accessibleUserIds()` method will need updating — but this centralizes the change to one location.
- The 500ms debounce on `users/index` person_search and `units/chart` search is intentional — person search involves a backend lookup by n_code and the extra latency prevents rapid-fire requests.
- If future components need the User ID list, they should call `app(AccessService::class)->accessibleUserIds()` instead of reimplementing the query.
