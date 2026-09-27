# Plan 6: Missing Chunked Reading in HardwareAuditsExport

> Written against commit: `4d8477e` (beta/sydney)
> Category: Performance | Effort: S | Impact: HIGH

## Problem

`HardwareAuditsExport` loads the entire audit trail into memory in a single `->get()` call. For hardware items with thousands of audit records (common in production after months of updates, bulk operations, and imports), this causes PHP memory exhaustion or excessive peak memory usage.

**Compare** with `HardwareExport` (lines 15, 71-88) which already implements `WithChunkReading` with a 500-record chunk size:

```php
class HardwareExport implements FromCollection, ShouldAutoSize, WithChunkReading, ...
{
    protected int $chunkSize = 500;

    public function chunkCollection(): Collection
    {
        $chunk = $this->query
            ->where('id', '>', $this->lastId)
            ->orderBy('id')
            ->take($this->chunkSize)
            ->get();

        if ($chunk->isNotEmpty()) {
            $this->lastId = $chunk->last()->id;
        }

        return $chunk;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }
}
```

### Evidence

- `app/Exports/HardwareAuditsExport.php:25-31` — `collection()` calls `->get()` without chunking, loading all audit rows into memory at once
- `app/Exports/HardwareAuditsExport.php:13` — class does not implement `WithChunkReading`
- `app/Exports/HardwareExport.php:15` — sibling export class already implements `WithChunkReading` correctly
- `app/Http/Controllers/Api/HardwareAuditController.php:244-272` — `export()` passes the full query to `HardwareAuditsExport` with eager-loaded relations, multiplying memory per row

### Why this matters

Each `HardwareAudit` row includes a JSONB `changes` column (potentially large) and an eager-loaded `user.person` relation. On a hardware item with 10,000+ audit records (realistic after 6 months of production use), a single export can consume 200-500 MB of PHP memory.

## Solution

Add `WithChunkReading` to `HardwareAuditsExport`, following the exact pattern from `HardwareExport`. Implement `chunkCollection()` and `chunkSize()` methods, keeping `collection()` as a fallback.

### Before (HardwareAuditsExport.php:13-31)

```php
class HardwareAuditsExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected Builder $query;

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    public function collection(): Collection
    {
        return $this->query->with('user.person:id,n_code,f_name,l_name')
            ->latest('created_at')
            ->get();
    }
}
```

### After

```php
use Maatwebsite\Excel\Concerns\WithChunkReading;

class HardwareAuditsExport implements FromCollection, WithHeadings, WithMapping, WithTitle, WithChunkReading
{
    protected Builder $query;

    protected int $lastId = 0;

    protected int $chunkSize = 500;

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    /**
     * Fallback for small datasets (used by FromCollection).
     */
    public function collection(): Collection
    {
        return $this->query->with('user.person:id,n_code,f_name,l_name')
            ->latest('created_at')
            ->get();
    }

    /**
     * Chunked export — processes records in batches to avoid loading the
     * entire audit trail into memory at once.
     */
    public function chunkCollection(): Collection
    {
        $chunk = $this->query
            ->with('user.person:id,n_code,f_name,l_name')
            ->where('id', '>', $this->lastId)
            ->orderBy('id')
            ->take($this->chunkSize)
            ->get();

        if ($chunk->isNotEmpty()) {
            $this->lastId = $chunk->last()->id;
        }

        return $chunk;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }
}
```

Key differences from `HardwareExport`:
- The `with('user.person:id,n_code,f_name,l_name')` eager-load is kept in **both** `collection()` and `chunkCollection()` — removing it would break `map()` which accesses `$audit->user->name` and `$audit->user?->n_code`
- Order uses `id` for stable chunking (cursor-based pagination), not `created_at` (which could tie and skip rows)

## Files in Scope

- `app/Exports/HardwareAuditsExport.php`

## Files Out of Scope

- `app/Exports/HardwareExport.php` — already has chunking, no changes needed
- `app/Http/Controllers/Api/HardwareAuditController.php` — no changes needed (query builder is passed as-is)
- `phpstan-baseline.neon` — existing baseline entries for this class remain valid

## Steps

### Step 1: Add WithChunkReading interface and implementation
1. Add `use Maatwebsite\Excel\Concerns\WithChunkReading;` import
2. Add `WithChunkReading` to the `implements` clause
3. Add `$lastId` and `$chunkSize` properties
4. Implement `chunkCollection()` method with eager-loaded `user.person` relation and cursor-based chunking
5. Implement `chunkSize()` method returning `$this->chunkSize`
6. Keep existing `collection()` as fallback

### Step 2: Verify eager loading is preserved
1. Confirm `chunkCollection()` includes `->with('user.person:id,n_code,f_name,l_name')` — without this, `map()` at line 56 will trigger N+1 queries or throw null access errors
2. Confirm `collection()` also keeps the eager load (unchanged from original)

### Step 3: Run quality gates
```bash
composer phpstan    # Ensure no new errors
composer pint       # Format
composer test       # All tests pass
```

### Step 4: Verify memory improvement (manual)
1. Create a test hardware item with 1000+ audit records
2. Export via the audit trail CSV endpoint
3. Compare peak memory before/after (PHP `memory_get_peak_usage()` in controller)

## Test Plan

No existing tests cover any export class. Add:

1. **Unit test for chunking behavior**: Create `tests/Unit/Exports/HardwareAuditsExportTest.php`
   - Seed 1200 HardwareAudit records for a single hardware item
   - Instantiate `HardwareAuditsExport` with the query
   - Assert `chunkSize()` returns 500
   - Call `chunkCollection()` 3 times, verify 500 + 500 + 200 records
   - Verify `$lastId` advances correctly
   - Verify eager-loaded `user.person` is present on each model

2. **Integration test for export endpoint**: Add to `tests/Feature/HardwareAuditTest.php` (if exists) or create one
   - Seed hardware + 50 audit records + user with person
   - Call `GET /api/hardware/{id}/audits/export`
   - Assert 200 response with Excel download
   - Assert file contains correct headings and row count

3. **Verify map() works with chunked data**:
   - Ensure each chunk from `chunkCollection()` has the `user.person` relation loaded
   - Call `map()` on a record from the last chunk — should not trigger N+1

```bash
composer test       # All tests pass including new ones
```

## Maintenance Note

- The chunk size of 500 matches `HardwareExport` — keep them synchronized if either changes
- If the project switches from PostgreSQL to another driver, the cursor-based pagination (`where id > ?`) remains driver-agnostic — no PostgreSQL-specific syntax used
- The `collection()` fallback is still needed because Maatwebsite Excel uses it when `WithChunkReading` is not detected or for small datasets
- If future audit tables add soft deletes, ensure `chunkCollection()` doesn't skip soft-deleted rows that the original query would include

## Done Criteria

- [ ] `HardwareAuditsExport` implements `WithChunkReading`
- [ ] `chunkCollection()` method exists with eager-loaded `user.person` relation
- [ ] `chunkSize()` returns 500
- [ ] `collection()` still works as fallback (unchanged logic)
- [ ] No new PHPStan errors (`composer phpstan`)
- [ ] Pint formatted (`composer pint`)
- [ ] All tests pass (`composer test`)
- [ ] `grep -rn "WithChunkReading" app/Exports/` shows both HardwareExport and HardwareAuditsExport
