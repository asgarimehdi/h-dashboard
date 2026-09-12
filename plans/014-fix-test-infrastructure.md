# Plan 014: Fix test infrastructure — assertTrue(true) + reduce setup duplication

| Field      | Value                              |
|------------|------------------------------------|
| Category   | tests                              |
| Effort     | M                                  |
| Risk       | MED                                |
| Priority   | P2                                 |
| Depends on | none                               |
| Base SHA   | 5f9c24e                            |
| Branch     | celin                              |

---

## 1. Problem

Three test files contain `assertTrue(true)` placeholder assertions that mask
unclear behavior instead of testing it. Separately, 122 test files duplicate
raw `DB::table()` insert blocks for tahsils/estekhdams/semats/radifs instead
of using the existing `InteractsWithTestSetup` trait.

## 2. What exists today

### 2a. assertTrue(true) placeholders

| File | Line | Context |
|------|------|---------|
| `tests/Feature/UnitTicketCapabilityTest.php` | 114 | `toggleTicketCapability` with non-existent unit (id: 99999). Comment says "Should not throw." |
| `tests/Feature/UnitTreePickerPartialTest.php` | 346 | Tree picker root unit assertion. Comment says the empty-state path is in the template but not reachable with current test data. |
| `tests/Feature/TicketsMonitoringLivewireTest.php` | 318 | Out-of-scope user hit `$this->error()` which throws `BadMethodCallException` because the Monitoring component lacks the Toast trait. The comment documents this as a "pre-existing bug." |

### 2b. Test setup duplication

`tests/Support/Concerns/InteractsWithTestSetup` provides:
- `createUserWithUnit(array $unitData, array $permissions): array` — creates Unit, Person, User, grants permissions
- `createHardware(array $data): Hardware`
- `assertCacheInvalidated(string $cacheKey): void`
- `assertQueryCount(int $expected, Closure $callback): void`
- `assertNoNPlusOne(Closure $callback, int $maxQueries = 5): void`

**Currently used by**: only 1 file (`tests/Feature/CacheInvalidationTest.php`).

**Raw DB::table insert blocks** appear in 122+ test files across 237+ locations, inserting into `tahsils`, `estekhdams`, `semats`, `radifs` tables with identical boilerplate.

The trait currently lacks a shared "seed lookup tables" helper. That's the main missing piece.

## 3. Implementation steps

### Step 1 — Enhance InteractsWithTestSetup trait

Add a `seedLookupTables` method to `tests/Support/Concerns/InteractsWithTestSetup.php`:

```php
/** Seed the 4 lookup tables used across almost every test file. */
protected function seedLookupTables(): void
{
    DB::table('tahsils')->insert(['id' => 1, 'name' => 'کارشناسی', 'code' => 'BSC']);
    DB::table('tahsils')->insert(['id' => 2, 'name' => 'کارشناسی ارشد', 'code' => 'MSC']);

    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'رسمی', 'code' => 'OFF']);
    DB::table('estekhdams')->insert(['id' => 2, 'name' => 'پیمانی', 'code' => 'CON']);

    DB::table('semats')->insert(['id' => 1, 'name' => 'کارشناس', 'code' => 'EXP']);
    DB::table('semats')->insert(['id' => 2, 'name' => 'کارشناس ارشد', 'code' => 'SE']);

    DB::table('radifs')->insert(['id' => 1, 'name' => '۱', 'code' => '1']);
    DB::table('radifs')->insert(['id' => 2, 'name' => '۲', 'code' => '2']);
}
```

> **Note**: Verify exact column names by reading the migration files for
> these 4 tables. The above is a guess based on common patterns. Adjust
> during implementation. Use `upsert` with unique constraints where
> available to avoid duplicate-insert errors when called multiple times.

### Step 2 — Replace assertTrue(true) in UnitTicketCapabilityTest

**File**: `tests/Feature/UnitTicketCapabilityTest.php:106-115`

Current:
```php
it('toggleTicketCapability handles non-existent unit', function () {
    $user = makeTicketManager();
    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', 99999);
    // Should not throw
    $this->assertTrue(true);
});
```

Replace with meaningful assertions:
```php
it('toggleTicketCapability handles non-existent unit', function () {
    $user = makeTicketManager();
    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', 99999)
        ->assertHasNoErrors();
    // Unit still doesn't exist
    $this->assertNull(Unit::find(99999));
});
```

If `toggleTicketCapability` sets an error flash message or session alert, use `->assertSessionHas('flash_message', ...)` or `->assertSee(...)` instead.

### Step 3 — Replace assertTrue(true) in UnitTreePickerPartialTest

**File**: `tests/Feature/UnitTreePickerPartialTest.php:333-347`

Current (simplified):
```php
$component->call('$set', 'unitModal', true);
$component->assertSee('مرکز بهداشت');
$html = $component->html();
// The "واحدی یافت نشد" message is only shown when $roots->isEmpty()
$this->assertTrue(true);
```

The test already does useful work (`assertSee`). Replace the trailing `assertTrue(true)` with:
```php
// Verify the component rendered without errors
$component->assertOk();
// Verify the root unit is visible in the tree
$this->assertStringContainsString('مرکز بهداشت', $html);
```

### Step 4 — Replace assertTrue(true) in TicketsMonitoringLivewireTest

**File**: `tests/Feature/TicketsMonitoringLivewireTest.php:305-318`

Current:
```php
try {
    Livewire::test('tickets.monitoring')
        ->call('showTicket', $ticket->id);
} catch (\BadMethodCallException $e) {
    // Expected: the component's $this->error() throws without Toast trait.
}
$this->assertTrue(true, 'out-of-scope shows error bug is pre-existing; in-scope works');
```

This documents a real bug (missing Toast trait). Replace with an explicit assertion and a TODO:
```php
try {
    Livewire::test('tickets.monitoring')
        ->call('showTicket', $ticket->id);
    $this->fail('Expected BadMethodCallException for out-of-scope user (missing Toast trait)');
} catch (\BadMethodCallException $e) {
    $this->assertStringContainsString('error', strtolower($e->getMessage()));
}
// TODO: fix tickets.monitoring to use Toast trait so out-of-scope users get a user-friendly error
```

### Step 5 — Migrate high-churn tests to InteractsWithTestSetup (incremental)

**Priority order** (highest duplication first):

1. `tests/Feature/TicketApiTest.php` — 20 DB::table inserts
2. `tests/Feature/HardwareRestoreScopeTest.php` — 10 inserts
3. `tests/Feature/HardwareScopeTest.php` — 8 inserts
4. `tests/Feature/TicketCommentsEdgeCasesTest.php` — 8 inserts
5. `tests/Feature/HrApiTest.php` — 8 inserts

For each file:
1. Add `use Tests\Support\Concerns\InteractsWithTestSetup;`
2. Add `use InteractsWithTestSetup;` inside the class/uses block.
3. Replace the raw `DB::table('tahsils')->insert(...)` block in `setUp()` with `$this->seedLookupTables()`.
4. Verify the test still passes.

> **Migration strategy**: Do NOT convert all 122 files in this plan. Convert
> the top 5 highest-churn files. Leave the rest as a follow-up. The trait
> is backward-compatible — adding `use InteractsWithTestSetup` never breaks
> existing code.

## 4. Files changed

| File | Action |
|------|--------|
| `tests/Support/Concerns/InteractsWithTestSetup.php` | **Modify** — add `seedLookupTables()` |
| `tests/Feature/UnitTicketCapabilityTest.php` | **Modify** — replace assertTrue(true) |
| `tests/Feature/UnitTreePickerPartialTest.php` | **Modify** — replace assertTrue(true) |
| `tests/Feature/TicketsMonitoringLivewireTest.php` | **Modify** — replace assertTrue(true) |
| `tests/Feature/TicketApiTest.php` | **Modify** — use seedLookupTables() |
| `tests/Feature/HardwareRestoreScopeTest.php` | **Modify** — use seedLookupTables() |
| `tests/Feature/HardwareScopeTest.php` | **Modify** — use seedLookupTables() |
| `tests/Feature/TicketCommentsEdgeCasesTest.php` | **Modify** — use seedLookupTables() |
| `tests/Feature/HrApiTest.php` | **Modify** — use seedLookupTables() |

## 5. Verification

1. `grep -rn "assertTrue(true)" tests/` — returns **0 matches**.
2. `composer test` — all tests pass (including the 5 migrated files).
3. `grep -rn "DB::table.*insert" tests/Feature/TicketApiTest.php` — returns **0 matches** (confirming migration).
4. Spot-check 2-3 non-migrated files still pass to confirm backward compatibility of the trait.

## 6. Risks and mitigations

| Risk | Mitigation |
|------|------------|
| `seedLookupTables()` column names don't match migrations | Read migration files during implementation; adjust column names. |
| Existing tests already insert different values for same IDs | Use `DB::table(...)->insertOrIgnore()` or `upsert` to handle idempotency. |
| Monitoring test fix reveals deeper bug | Keep the explicit `catch` + assertion pattern; don't fix the Toast bug in this plan — just document it with a TODO. |
| Breaking tests during migration | Run `composer test` after each file migration, before moving to the next. |
