# Plan 014: Fix Missing FK on persons.u_id

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Medium  
**Category:** Data Integrity  

## Problem

The `persons` table has a `u_id` column (foreign key to `units.id`) with an index but **no foreign key constraint**. This means:
1. Orphaned person records can exist (u_id references a deleted unit)
2. Cascade/restrict behavior is missing — deleting a unit doesn't prevent or clean up person references
3. Referential integrity is not enforced at the DB level

## Current State

**File:** `database/migrations/2025_03_20_000005_create_persons_table.php:20`

```php
$table->foreignId('u_id')->index();
// ← Has index, but NO ->constrained() or ->foreign() definition
```

Compare with other FKs in the same migration (lines 23-41):

```php
$table->foreign('e_id', 'persons_e_id_fk')
    ->references('id')->on('estekhdams')
    ->onDelete('restrict')
    ->onUpdate('cascade');

$table->foreign('t_id', 'persons_t_id_fk')
    ->references('id')->on('tahsils')
    ->onDelete('restrict')
    ->onUpdate('cascade');
// ... same pattern for s_id, r_id
```

All other FKs in the `persons` table use `onDelete('restrict')` — `u_id` should follow the same pattern for consistency.

## Proposed Fix

Create a new migration to add the missing FK constraint:

```php
// database/migrations/2026_09_02_000002_add_u_id_fk_to_persons_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First: clean up any orphaned u_id references
        // (set to a default unit or NULL depending on business logic)
        $this->cleanOrphanedPersonUnitRefs();

        Schema::table('persons', function (Blueprint $table) {
            $table->foreign('u_id', 'persons_u_id_fk')
                ->references('id')->on('units')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropForeign('persons_u_id_fk');
        });
    }

    private function cleanOrphanedPersonUnitRefs(): void
    {
        // Remove person records referencing non-existent units
        // Or update them to reference a default/unknown unit
        // Choose based on business requirements
        DB::table('persons')
            ->whereNotIn('u_id', function ($query) {
                $query->select('id')->from('units');
            })
            ->update(['u_id' => DB::raw('(SELECT id FROM units LIMIT 1)')]);
    }
};
```

## Files to Modify

| File | Change |
|------|--------|
| `database/migrations/2026_09_02_000002_add_u_id_fk_to_persons_table.php` | New migration: add FK constraint |

**Out of scope:** The original migration (don't modify it), Person model, other orphaned FKs.

## Verification

```bash
# 1. Check for orphaned u_id references
php scripts/boost_tool.php query '{"sql": "SELECT COUNT(*) as orphaned FROM persons p LEFT JOIN units u ON p.u_id = u.id WHERE u.id IS NULL"}'
# Expected: 0 (after cleanup)

# 2. Run the new migration
php artisan migrate
# Expected: Migration ran successfully

# 3. Verify FK exists in schema
php scripts/boost_tool.php query '{"sql": "SELECT conname, contype FROM pg_constraint WHERE conrelid = '\''persons'\''::regclass AND conname = '\''persons_u_id_fk'\''"}'
# Expected: returns one row with contype = '\''f'\''

# 4. Run full test suite
composer test
# Expected: 928+ pass
```

## Test Plan

```php
it('prevents deleting a unit that has persons', function () {
    $unit = Unit::factory()->create();
    Person::factory()->create(['u_id' => $unit->id]);

    $this->expectException(\Illuminate\Database\QueryException::class);
    
    $unit->delete();
});

it('prevents inserting person with non-existent u_id', function () {
    $this->expectException(\Illuminate\Database\QueryException::class);
    
    DB::table('persons')->insert([
        'n_code' => '1234567890',
        'f_name' => 'Test',
        'l_name' => 'User',
        'u_id' => 999999, // non-existent unit
        't_id' => 1,
        'e_id' => 1,
        's_id' => 1,
        'r_id' => 1,
    ]);
});
```

## STOP Conditions

- If the cleanup query modifies production data (test in staging first)
- If there are thousands of orphaned records (batch the cleanup)
- If the `units` table is empty (the cleanup subquery would fail)
- If other tables also have missing FKs (extend scope)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Orphaned data blocks migration | Migration fails | Pre-clean in same migration |
| Units table has no rows | Cleanup query fails | Check row count first |
| Existing code inserts persons with bad u_id | QueryException errors | Audit insert points |

## Maintenance Notes

- Apply the same FK check to other tables: check `information_schema.table_constraints` for missing FKs
- Consider running a periodic orphan detection query
- Document the FK constraint in the data model reference
