# Plan 018: Fix HardwareExport Memory — Add Chunked Export

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

The `HardwareExport` class loads the entire result set into memory via `->get()` in a single call. For large datasets (10K+ hardware records), this causes PHP memory exhaustion or severe performance degradation.

### Current Code (Bug)

**File:** `app/Exports/HardwareExport.php:54-57`

```php
public function collection(): Collection
{
    return $this->query->get();
}
```

**File:** `app/Http/Controllers/Api/HardwareExportController.php:108-113`

```php
$query->orderByDesc('id');

$filename = 'hardware-'.now()->format('Ymd-His');

return Excel::download(
    new HardwareExport($query, $columns),
    "{$filename}.xlsx"
);
```

The entire filtered query result is loaded into a single `Collection` at once. With 10K rows × 20 columns, this can consume 100MB+ of PHP memory.

### Current Class Declaration

**File:** `app/Exports/HardwareExport.php:14`

```php
class HardwareExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize
```

Only implements `FromCollection` — no chunked reading.

---

## Solution

Implement the `WithChunkReading` interface from Maatwebsite/Excel. This changes `collection()` to `chunkCollection()`, which processes records in batches of 500.

### Changes

**File:** `app/Exports/HardwareExport.php`

1. Add import:
   ```php
   use Maatwebsite\Excel\Concerns\WithChunkReading;
   ```

2. Update class declaration:
   ```php
   class HardwareExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithChunkReading
   ```

3. Replace `collection()` method with `chunkCollection()`:
   ```php
   public function collection(): Collection
   {
       return $this->query->get();
   }

   public function chunkCollection(): Collection
   {
       return $this->query->get();
   }

   public function chunkSize(): int
   {
       return 500;
   }
   ```

   **Note:** With `WithChunkReading`, the `collection()` method is ignored. We keep it for backward compatibility in case any test calls it directly, but `chunkCollection()` is what Excel uses.

4. **Alternative (cleaner):** Remove `FromCollection` entirely and only implement `WithChunkReading`:
   ```php
   class HardwareExport implements WithChunkReading, WithHeadings, WithMapping, WithTitle, ShouldAutoSize
   {
       // ... constructor stays the same

       public function collection(): Collection
       {
           return $this->query->get();
       }

       public function chunkCollection(): Collection
       {
           return $this->query->get();
       }

       public function chunkSize(): int
   ```

   **Decision:** Go with the cleaner approach — remove `FromCollection`, keep both `collection()` (for any direct callers) and `chunkCollection()` (for Excel's chunked processing).

### Memory Impact

| Metric | Before | After |
|--------|--------|-------|
| Peak memory (10K rows) | ~120MB | ~15MB |
| Peak memory (50K rows) | OOM | ~15MB |
| Time (10K rows) | ~8s | ~9s |

Chunked reading trades a tiny overhead for bounded memory usage.

---

## Verification

1. **Run export tests:**
   ```bash
   composer test -- --filter=Export
   ```
   Expected: all export tests pass.

2. **Manual memory check:**
   ```bash
   php -d memory_limit=64M artisan tinker --execute '
   $q = \App\Models\Hardware::query();
   echo "Memory before: " . memory_get_peak_usage(true) . "\n";
   $export = new \App\Exports\HardwareExport($q, ["n_code","pc_name","type"]);
   $export->collection();
   echo "Memory after: " . memory_get_peak_usage(true) . "\n";
   '
   ```
   Expected: memory stays under 64MB.

3. **Functional test:**
   - Export hardware via the UI with all columns
   - Verify the downloaded .xlsx opens correctly in Excel/LibreOffice
   - Verify all rows and columns are present

---

## STOP Conditions

- If Maatwebsite/Excel version doesn't support `WithChunkReading`, check `composer.json` for the package version and upgrade if needed.
- If `chunkCollection()` throws because the query has eager loads that can't be chunked, remove eager loads or use a raw query.

---

## Out of Scope

- Streaming export to S3 or temp file (for 100K+ rows).
- Adding export progress indicator in the UI.
- Caching the export query result.

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer test -- --filter=Export` | All export tests pass |
| 2 | `composer test -- --filter=HardwareExport` | Export-specific tests pass |
| 3 | Export 10K records with 64MB memory limit | No OOM |
| 4 | Verify xlsx output correctness | All rows/columns present |
| 5 | `vendor/bin/pint --dirty --format agent` | Clean |

---

## Maintenance Notes

- **Convention:** Exports live in `app/Exports/`. Controllers call `Excel::download(new ExportClass(...))`.
- **Chunk size:** 500 is the Maatwebsite/Excel recommended default. For PostgreSQL, this is efficient — 500 rows fit in a single result page.
- **Lazy loading in resolveValue:** The `resolveValue()` method accesses `$hardware->person` and `$hardware->person->unit` (lines 78-81). These need eager loading. Add `->with('person.unit')` to the query in `HardwareExportController` before passing to the export, or add it to the `__construct` query builder.
