# Plan 13: 124 test files duplicate raw DB inserts instead of using shared helpers

> Written against commit: `04c4393` (sydney)
> Category: Test | Effort: L | Impact: Medium

## Problem

**58 test files** define their own `createUserWithUnit()` method. **100+ test files** insert lookup rows via raw `DB::table('tahsils')->insert(...)` in `setUp()`. **35 `setval()` calls** manually resync Postgres sequences. Only **1 test file** (`CacheInvalidationTest.php`) uses the shared `InteractsWithTestSetup` trait.

The project already has factories for all lookup models (`TahsilFactory`, `EstekhdamFactory`, `SematFactory`, `RadifFactory`) and the `InteractsWithTestSetup` trait with `createUserWithUnit()` and `createHardware()` helpers, but nearly every test file re-implements these patterns from scratch. This creates:

1. **Maintenance burden** — a schema change to `persons` or `users` requires editing 58+ files
2. **Inconsistent patterns** — some tests return `User`, some return `array`, some seed permissions differently
3. **Sequence collision bugs** — manual `setval()` calls use inconsistent SQL (`COALESCE` vs `GREATEST` variants)
4. **Wasted setup time** — raw `DB::table()->insert()` bypasses model events and factories

### Evidence

**Duplicated `createUserWithUnit()` (57 test files + 1 trait):**
- `tests/Feature/HardwareIndexLivewireTest.php:41-58` — returns `array`, uses `Unit::create()` + raw `Person::create()` + `Hash::make()`
- `tests/Feature/TicketApiTest.php:31-58` — returns `array`, uses `DB::table()->insertGetId()` for lookups + `Permission::firstOrCreate()`
- `tests/Feature/MapsUnitLivewireTest.php:37-56` — returns `User` (not array), hardcoded `t_id => 1`
- `tests/Feature/ReportsPersonsLivewireTest.php:40-56` — returns `User`, conditional permission via `if ($permission)`
- `tests/Feature/TodoApiTest.php:28-47` — returns `array`, uses `PermissionSeeder::class` in the helper itself

**Raw lookup inserts in setUp() (100+ files):**
- `tests/Feature/HardwareIndexLivewireTest.php:29-38` — 4 `DB::table()->insert()` + 4 `setval()` calls
- `tests/Feature/MapsUnitLivewireTest.php:25-34` — 4 inserts + 4 `setval()` with `DB::select()`
- `tests/Feature/ReportsPersonsLivewireTest.php:26-37` — 4 inserts + 6 `setval()` calls (includes `units` and `persons`)

**Factories that exist but are bypassed:**
- `database/factories/TahsilFactory.php` — generates realistic Persian education levels
- `database/factories/EstekhdamFactory.php`
- `database/factories/SematFactory.php`
- `database/factories/RadifFactory.php`
- `database/factories/PersonFactory.php:39-44` — already calls `lookupId()` which auto-creates lookups
- `database/factories/UserFactory.php:45-63` — `configure()` auto-creates backing Person

**The only test using the shared trait:**
- `tests/Feature/CacheInvalidationTest.php:10,15` — `use Tests\Support\Concerns\InteractsWithTestSetup;` + `uses(InteractsWithTestSetup::class);`

## Solution

### Phase 1: Enhance `InteractsWithTestSetup` trait

Add a `seedLookupTables()` helper that creates lookup rows using factories (or fallback inserts) and resyncs sequences. Add flexible `createUserWithUnit()` signatures.

### Phase 2: Migrate test files in batches

Replace local `createUserWithUnit()`, `setUp()` lookup inserts, and `createHardware()` with calls to the shared trait. Process ~15-20 files per batch.

### Before (typical test file)

```php
class HardwareIndexLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        DB::statement("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
        // ... 3 more setval calls
    }

    protected function createUserWithUnit(string $permission = 'manage_hardware'): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo($permission);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        // ... 30 lines of boilerplate
    }
}
```

### After

```php
use Tests\Support\Concerns\InteractsWithTestSetup;

covers(Hardware::class);

class HardwareIndexLivewireTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // createHardwareForUser() stays local — it's domain-specific to this test
}
```

## Files in Scope

**Trait to enhance:**
- `tests/Support/Concerns/InteractsWithTestSetup.php`

**Test files to migrate (57 files with local `createUserWithUnit()`):**
- All files listed in `tests/Feature/` that define `protected function createUserWithUnit`
- Grouped by pattern (see Steps)

**Factories that already exist (no changes needed):**
- `database/factories/TahsilFactory.php`
- `database/factories/EstekhdamFactory.php`
- `database/factories/SematFactory.php`
- `database/factories/RadifFactory.php`
- `database/factories/PersonFactory.php`
- `database/factories/UserFactory.php`

## Files Out of Scope

- `tests/Feature/HardwareImport/` — import tests have specialized setup
- `tests/Feature/Kargozini/` — separate domain with its own patterns
- `tests/Feature/OtherModelsTest.php` — uses raw DB to test model constraints
- `tests/Feature/LookupSimpleModelsTest.php` / `LookupModelsTest.php` — intentionally test raw lookup behavior
- `tests/e2e/` — Playwright tests, not Pest
- `tests/Unit/` — only 7 files, different testing concerns

## Steps

### Step 1: Enhance `InteractsWithTestSetup` trait

1. Add `seedLookupTables()` method that creates lookup rows using `Tahsil::factory()->create()` etc. and resyncs sequences
2. Add `createUserWithUnitWithPerms(array $permissions, string $role = null): array` that handles permission seeding
3. Keep existing `createUserWithUnit()` and `createHardware()` unchanged for backward compat
4. Add `resyncSequence(string $table): void` private helper

```php
// tests/Support/Concerns/InteractsWithTestSetup.php — additions

protected function seedLookupTables(): void
{
    Tahsil::factory()->count(1)->firstOrCreate(['name' => 'Test']);
    Estekhdam::factory()->count(1)->firstOrCreate(['name' => 'Test']);
    Semat::factory()->count(1)->firstOrCreate(['name' => 'Test']);
    Radif::factory()->count(1)->firstOrCreate(['name' => 'Test']);
    // Resync all four sequences in one statement
    DB::statement("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
    DB::statement("SELECT setval('estekhdams_id_seq', COALESCE((SELECT MAX(id) FROM estekhdams), 1))");
    DB::statement("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");
    DB::statement("SELECT setval('radifs_id_seq', COALESCE((SELECT MAX(id) FROM radifs), 1))");
}

protected function createUserWithUnitWithPerms(array $permissions = [], ?string $role = null): array
{
    $this->seedLookupTables();
    $unit = Unit::factory()->create();
    $person = Person::factory()->create(['u_id' => $unit->id]);
    $user = User::factory()->create(['n_code' => $person->n_code]);
    if ($role) {
        $user->assignRole($role);
    }
    if ($permissions) {
        $user->givePermissionTo($permissions);
    }
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);
    return ['user' => $user, 'unit' => $unit];
}
```

5. Verify: run existing trait test `composer test tests/Feature/CacheInvalidationTest.php`

### Step 2: Migrate Batch 1 — Hardware tests (9 files)

Files that define local `createHardware()`:
- `tests/Feature/HardwareIndexLivewireTest.php`
- `tests/Feature/HardwareAuditModalLivewireTest.php`
- `tests/Feature/HardwareExportTest.php`
- `tests/Feature/HardwareFiltersLivewireTest.php`
- `tests/Feature/HardwareAuditModelTest.php`
- `tests/Feature/HardwareModelTest.php`
- `tests/Feature/HardwareTableLivewireTest.php`
- `tests/Feature/HardwareTrashModalLivewireTest.php`

For each file:
1. Add `use InteractsWithTestSetup;` trait
2. Remove `protected function createUserWithUnit()` method
3. Remove `protected function createHardware()` if it matches the shared version
4. Remove `setUp()` lookup inserts and `setval()` calls; replace with `$this->seedLookupTables()`
5. Update calls: `$this->createUserWithUnit()` → `$this->createUserWithUnitWithPerms(['manage_hardware'])`
6. Verify: `composer test tests/Feature/{File}.php`

### Step 3: Migrate Batch 2 — Map tests (10 files)

- `tests/Feature/MapsUnitLivewireTest.php`
- `tests/Feature/MapsRoute2LivewireTest.php`
- `tests/Feature/MapsRouteLivewireTest.php`
- `tests/Feature/MapsMapLivewireTest.php`
- `tests/Feature/MapsInteractiveLivewireTest.php`
- `tests/Feature/MapsPointLivewireTest.php`
- `tests/Feature/MapDashboardLivewireTest.php`
- `tests/Feature/MapDashboardTest.php`
- `tests/Feature/MapsPagesTest.php`

Same pattern as Step 2, using `'map'` permission.

### Step 4: Migrate Batch 3 — Ticket tests (10 files)

- `tests/Feature/TicketApiTest.php`
- `tests/Feature/TicketCommentTest.php`
- `tests/Feature/TicketCommentModelTest.php`
- `tests/Feature/TicketCommentsRefreshTest.php`
- `tests/Feature/TicketsCreateLivewireTest.php`
- `tests/Feature/TicketsInboxLivewireTest.php`
- `tests/Feature/TicketsMonitoringLivewireTest.php`
- `tests/Feature/TicketControllerEdgeCasesTest.php`
- `tests/Feature/UnitTicketCapabilityTest.php`

Handle the 6 files that use `Permission::firstOrCreate()` — consolidate into the trait.

### Step 5: Migrate Batch 4 — Reports, HR, IT tests (15 files)

- `tests/Feature/ReportsPersonsLivewireTest.php`
- `tests/Feature/ReportsAdvancedLivewireTest.php`
- `tests/Feature/ReportsMapNoBoundaryLivewireTest.php`
- `tests/Feature/ReportsIndexLivewireTest.php`
- `tests/Feature/ReportsApiTest.php`
- `tests/Feature/HrOrgNodeLivewireTest.php`
- `tests/Feature/HrApiTest.php`
- `tests/Feature/ItComponentsLivewireTest.php`
- `tests/Feature/ItNetworkTrafficLivewireTest.php`
- `tests/Feature/ToolsLivewireTest.php`
- `tests/Feature/ToolsPageTest.php`
- `tests/Feature/NotificationsBellLivewireTest.php`
- `tests/Feature/NotificationServiceTest.php`
- `tests/Feature/NotificationApiTest.php`
- `tests/Feature/NotificationModelTest.php`

### Step 6: Migrate Batch 5 — Remaining tests (15 files)

- `tests/Feature/TodoApiTest.php`
- `tests/Feature/TodoModelTest.php`
- `tests/Feature/Jobs/JobsTest.php`
- `tests/Feature/SettingsBrowserNotificationTest.php`
- `tests/Feature/SettingsProfileTest.php`
- `tests/Feature/ActivityLogPageLivewireTest.php`
- `tests/Feature/HardwareTableLivewireTest.php`
- `tests/Feature/ChangePasswordTest.php`
- `tests/Feature/LogoutTest.php`
- `tests/Feature/ApiLoginTest.php`
- `tests/Feature/ApiRateLimitTest.php`
- `tests/Feature/SettingsCompactModeTest.php`
- `tests/Feature/DeleteAlreadyDeletedTodoTest.php`
- `tests/Feature/ActivityLogServiceTest.php`
- `tests/Feature/HardwareImportLivewireTest.php`

### Step 7: Verify

```bash
composer pint       # Format
composer phpstan    # No new errors
composer test       # All 1352 tests pass
```

### Step 8: Final grep audit

```bash
# Should return 0 results (except the trait itself and intentionally raw tests)
grep -rn "protected function createUserWithUnit" tests/Feature/
grep -rn "DB::table('tahsils')" tests/Feature/
grep -rn "DB::table('estekhdams')" tests/Feature/
```

## Test Plan

1. **Regression** — all 1352 tests must pass after each batch (`composer test`)
2. **Trait tests** — `CacheInvalidationTest.php` continues passing (uses the trait)
3. **Factory verification** — `Tahsil::factory()->create()` produces valid rows; `PersonFactory::lookupId()` returns correct IDs
4. **No stale patterns** — `grep -rn "DB::table('tahsils')" tests/Feature/` returns only intentionally-raw test files
5. **PHPStan** — `composer phpstan` clean
6. **Pint** — `composer pint` clean

## Maintenance Note

- New test files **must** `use InteractsWithTestSetup` and call `$this->seedLookupTables()` instead of raw inserts
- The trait's `createUserWithUnit()` returns `[User, Unit]` — this is the canonical signature. Tests that only need a User should destructure: `[$user] = $this->createUserWithUnit()`
- If a test needs a non-standard setup (e.g., custom lookup values), it can still define local helpers — but should call `$this->seedLookupTables()` first
- Postgres sequence resync is handled inside the trait — no manual `setval()` calls needed
- AGENTS.md gotcha: "Factories: Only UserFactory exists" is outdated after this plan. All 13 factories now exist and should be used

## Done Criteria

- [ ] `InteractsWithTestSetup` has `seedLookupTables()`, `createUserWithUnitWithPerms()` methods
- [ ] `grep -rn "protected function createUserWithUnit" tests/Feature/` returns 0 results
- [ ] `grep -rn "DB::table('tahsils')" tests/Feature/` returns ≤ 5 results (intentionally-raw tests only)
- [ ] `grep -rn "DB::table('estekhdams')" tests/Feature/` returns ≤ 5 results
- [ ] All 1352 tests pass (`composer test`)
- [ ] PHPStan clean (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] AGENTS.md updated: remove "Only UserFactory exists" gotcha
