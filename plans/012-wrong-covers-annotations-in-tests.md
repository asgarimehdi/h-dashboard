# Plan 012: Wrong covers() annotations in 12+ test files — coverage reports unreliable

> Written against commit: `c91b44d` (beta/sydney)
> Category: Test | Effort: S | Impact: MEDIUM

## Problem

Pest's `covers()` annotation restricts code-coverage tracking to the listed classes. When a test file declares `covers(SomeClass::class)` but actually exercises completely different code, two bad things happen:

1. **False negatives:** The truly-tested code's coverage is invisible — it never appears in the coverage report.
2. **False positives:** The listed class shows "untested" lines even though other tests exercise it, or shows zero lines covered even though the test runs successfully.

At least 12 test files have `covers()` pointing at the wrong class.

### Evidence

| Test File | Declared `covers()` | Actually Tests | Severity |
|---|---|---|---|
| `tests/Feature/AuthTest.php:16` | `HardwareController::class` | Web login, API login, logout (User/auth flows) | **High** |
| `tests/Feature/LogoutTest.php:15` | `HardwareController::class` | Logout functionality (User/auth) | **High** |
| `tests/Feature/ApiLoginTest.php:15` | `HardwareController::class` | API login with Sanctum tokens (User/auth) | **High** |
| `tests/Feature/ChangePasswordTest.php:16` | `HardwareController::class` | Password change Livewire component (User) | **High** |
| `tests/Feature/ApiRateLimitTest.php:16` | `HardwareController::class` | Throttle middleware on API/login routes (middleware/routes) | **High** |
| `tests/Feature/DashboardPageTest.php:14` | `Ticket::class` | Dashboard page rendering (anonymous Livewire component) | Medium |
| `tests/Feature/PagesRenderTest.php:17` | `Ticket::class` | Rendering of 10+ pages: settings, maps, IT, activity-log, etc. | **High** |
| `tests/Feature/ReportsPagesTest.php:17` | `Ticket::class` | Reports pages: advanced, units, todos, persons, map-no-boundary | **High** |
| `tests/Feature/ItPagesTest.php:14` | `ZabbixService::class` | IT networks/wireless Livewire pages | Medium |
| `tests/Feature/ToolsPageTest.php:14` | `ZabbixService::class` | Tools page Livewire component | Medium |
| `tests/Feature/ItLivewireTest.php:14` | `ZabbixService::class` | IT networks/wireless Livewire components (mount, data, modal) | Medium |
| `tests/Feature/ItComponentsLivewireTest.php:16` | `HardwareController::class` | IT networks/wireless Livewire components (data) | **High** |
| `tests/Feature/ReportsAdvancedLivewireTest.php:16` | `HardwareController::class` | reports.advanced Livewire component | **High** |
| `tests/Feature/OtherModelsTest.php:19` | `Ticket::class` | TaskActivity, Attachment, TicketComment, TicketCommentReaction | Medium |
| `tests/Feature/LookupSimpleModelsTest.php:16` | `Person::class` | Semat, Tahsil, Estekhdam, Radif models (NOT Person) | Medium |

**Root cause:** These files were likely bulk-generated with a default `covers()` value, or copy-pasted from hardware test files without updating the annotation.

## Solution

Replace each wrong `covers()` with the correct class(es) being exercised, or use `coversNothing()` for pure integration/feature tests that touch many classes.

**Decision rule:**
- Test exercises **one specific class** → `covers(ThatClass::class)`
- Test exercises **an anonymous Livewire component** (no class to reference) → `coversNothing()`
- Test exercises **multiple unrelated classes** (like `PagesRenderTest`) → `coversNothing()`
- Test exercises **a command** → `covers(TheCommand::class)`
- Test exercises **middleware** → `covers(TheMiddleware::class)`

### Proposed replacements

| File | Current | Replacement | Reason |
|---|---|---|---|
| `AuthTest.php` | `covers(HardwareController::class)` | `covers(User::class)` | Tests auth flows (login/logout) — User model is the primary exercised class |
| `LogoutTest.php` | `covers(HardwareController::class)` | `covers(User::class)` | Tests logout — User model exercised |
| `ApiLoginTest.php` | `covers(HardwareController::class)` | `covers(User::class)` | Tests API login — User model exercised |
| `ChangePasswordTest.php` | `covers(HardwareController::class)` | `covers(User::class)` | Tests password change — User model exercised |
| `ApiRateLimitTest.php` | `covers(HardwareController::class)` | `coversNothing()` | Tests middleware + login routes — no single class |
| `DashboardPageTest.php` | `covers(Ticket::class)` | `coversNothing()` | Tests anonymous dashboard Livewire component |
| `PagesRenderTest.php` | `covers(Ticket::class)` | `coversNothing()` | Tests 10+ different pages/components — no single class |
| `ReportsPagesTest.php` | `covers(Ticket::class)` | `coversNothing()` | Tests 5 report pages — no single class |
| `ItPagesTest.php` | `covers(ZabbixService::class)` | `coversNothing()` | Tests anonymous IT Livewire components |
| `ToolsPageTest.php` | `covers(ZabbixService::class)` | `coversNothing()` | Tests anonymous tools Livewire component |
| `ItLivewireTest.php` | `covers(ZabbixService::class)` | `coversNothing()` | Tests anonymous IT Livewire components |
| `ItComponentsLivewireTest.php` | `covers(HardwareController::class)` | `coversNothing()` | Tests anonymous IT Livewire components |
| `ReportsAdvancedLivewireTest.php` | `covers(HardwareController::class)` | `coversNothing()` | Tests anonymous reports Livewire component |
| `OtherModelsTest.php` | `covers(Ticket::class)` | `coversNothing()` | Tests 4 different models |
| `LookupSimpleModelsTest.php` | `covers(Person::class)` | `coversNothing()` | Tests Semat, Tahsil, Estekhdam, Radif — not Person |

## Files in Scope

- `tests/Feature/AuthTest.php`
- `tests/Feature/LogoutTest.php`
- `tests/Feature/ApiLoginTest.php`
- `tests/Feature/ChangePasswordTest.php`
- `tests/Feature/ApiRateLimitTest.php`
- `tests/Feature/DashboardPageTest.php`
- `tests/Feature/PagesRenderTest.php`
- `tests/Feature/ReportsPagesTest.php`
- `tests/Feature/ItPagesTest.php`
- `tests/Feature/ToolsPageTest.php`
- `tests/Feature/ItLivewireTest.php`
- `tests/Feature/ItComponentsLivewireTest.php`
- `tests/Feature/ReportsAdvancedLivewireTest.php`
- `tests/Feature/OtherModelsTest.php`
- `tests/Feature/LookupSimpleModelsTest.php`

## Files Out of Scope

- Tests with correctly declared `covers()` (e.g., `HardwareApiTest.php` → `HardwareController::class`)
- Unit tests (already correctly annotated)
- `tests/Feature/KargoziniTest.php` → `covers(Person::class)` is partially correct (kargozini tests Person CRUD) — can revisit separately

## Steps

### Step 1: Auth tests — replace covers(HardwareController) with covers(User)
1. In each of `AuthTest.php`, `LogoutTest.php`, `ApiLoginTest.php`, `ChangePasswordTest.php`:
   - Change `covers(HardwareController::class)` → `covers(User::class)`
   - Update the `use` import: remove `use App\Http\Controllers\Api\HardwareController;`, add `use App\Models\User;` if not present
2. Verify: `grep -rn 'covers(HardwareController' tests/Feature/Auth* tests/Feature/Logout* tests/Feature/ApiLogin* tests/Feature/ChangePassword*` returns 0 results

### Step 2: ApiRateLimitTest — replace with coversNothing
1. In `ApiRateLimitTest.php`:
   - Change `covers(HardwareController::class)` → `coversNothing()`
   - Remove the `use App\Http\Controllers\Api\HardwareController;` import
   - Add `use Pest\Covers\CoversNothing;` if needed (or just use the Pest function)
2. Verify: `grep -n 'covers(HardwareController' tests/Feature/ApiRateLimitTest.php` returns 0

### Step 3: Dashboard/Reports page tests — replace covers(Ticket) with coversNothing
1. In `DashboardPageTest.php`, `PagesRenderTest.php`, `ReportsPagesTest.php`:
   - Change `covers(Ticket::class)` → `coversNothing()`
   - Remove the `use App\Models\Ticket;` import if no longer needed by other code
2. Verify: `grep -rn "covers(Ticket::class)" tests/Feature/DashboardPageTest.php tests/Feature/PagesRenderTest.php tests/Feature/ReportsPagesTest.php` returns 0

### Step 4: IT/Tools tests — replace covers(ZabbixService/HardwareController) with coversNothing
1. In `ItPagesTest.php`, `ToolsPageTest.php`, `ItLivewireTest.php`:
   - Change `covers(ZabbixService::class)` → `coversNothing()`
   - Remove `use App\Services\ZabbixService;` import if no longer needed
2. In `ItComponentsLivewireTest.php`, `ReportsAdvancedLivewireTest.php`:
   - Change `covers(HardwareController::class)` → `coversNothing()`
   - Remove `use App\Http\Controllers\Api\HardwareController;` import
3. Verify: `grep -rn "covers(ZabbixService::class)\|covers(HardwareController::class)" tests/Feature/It* tests/Feature/Tools* tests/Feature/ReportsAdvanced*` returns 0

### Step 5: Other multi-class tests — replace covers(Ticket/Person) with coversNothing
1. In `OtherModelsTest.php`:
   - Change `covers(Ticket::class)` → `coversNothing()`
2. In `LookupSimpleModelsTest.php`:
   - Change `covers(Person::class)` → `coversNothing()`
3. Verify: `grep -rn "covers(Ticket::class)" tests/Feature/OtherModelsTest.php` returns 0

### Step 6: Run full test suite
```bash
composer test       # All 1352 tests pass
composer phpstan    # No new errors
composer pint       # Format
```

## Test Plan

1. Run `composer test` — all 1352 tests must pass (changing `covers()` does not affect test logic)
2. Run `vendor/bin/pest --coverage --min=80` — verify coverage still meets the 80% minimum
3. Verify no stale imports remain: `grep -rn 'use App\\Http\\Controllers\\Api\\HardwareController;' tests/Feature/AuthTest.php tests/Feature/LogoutTest.php tests/Feature/ApiLoginTest.php tests/Feature/ChangePasswordTest.php tests/Feature/ApiRateLimitTest.php tests/Feature/ItComponentsLivewireTest.php tests/Feature/ReportsAdvancedLivewireTest.php` returns 0
4. Verify correct annotations: `grep -rn 'covers(' tests/Feature/ | grep -v 'coversNothing' | grep -v 'covers(.*::class)'` returns 0

## Maintenance Note

- When adding new test files, determine `covers()` based on the actual class(es) exercised, not copy-pasted from other files.
- Anonymous Livewire components cannot be referenced in `covers()`. Use `coversNothing()` for feature tests of anonymous Livewire components.
- If the project later converts anonymous Livewire components to named classes, revisit `coversNothing()` annotations to add proper class coverage.
- PHPStan level 6 does not validate `covers()` annotations — this is a manual discipline check.

## Done Criteria

- [ ] All 15 test files have correct `covers()` or `coversNothing()` annotations
- [ ] `grep -rn 'covers(HardwareController' tests/Feature/AuthTest.php tests/Feature/LogoutTest.php tests/Feature/ApiLoginTest.php tests/Feature/ChangePasswordTest.php` returns 0
- [ ] `grep -rn 'covers(HardwareController' tests/Feature/ApiRateLimitTest.php tests/Feature/ItComponentsLivewireTest.php tests/Feature/ReportsAdvancedLivewireTest.php` returns 0
- [ ] `grep -rn 'covers(ZabbixService' tests/Feature/ItPagesTest.php tests/Feature/ToolsPageTest.php tests/Feature/ItLivewireTest.php` returns 0
- [ ] `grep -rn 'covers(Ticket' tests/Feature/DashboardPageTest.php tests/Feature/PagesRenderTest.php tests/Feature/ReportsPagesTest.php tests/Feature/OtherModelsTest.php` returns 0
- [ ] `grep -rn 'covers(Person' tests/Feature/LookupSimpleModelsTest.php` returns 0
- [ ] All existing tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] Coverage ≥ 80% (`vendor/bin/pest --coverage --min=80`)
