# Plan 005: Normalize Persian and escape LIKE wildcards in hardware export

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareExportController.php app/Http/Controllers/Api/HardwareController.php app/Traits/PersianNormalizer.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `70e35c2`, 2026-09-01

## Why this matters

The hardware list page (`HardwareController::index` + Livewire `hardware/index.blade.php`) normalizes Persian text (`ي→ی`, `ك→ک`, ZWNJ→space) via `PersianNormalizer::normalizeForSearch` and scopes searches consistently. The Excel export (`HardwareExportController::export`) duplicates the same filter logic but drops normalization and LIKE-escaping. Result: searching `ي` on the page finds `ی` records, but exporting with the same query misses them — user-visible data divergence. Unescaped `%`/`_` in `LIKE` also lets `search=%` match everything via full scan despite `pg_trgm` indexes.

## Current state

Files:

- `app/Http/Controllers/Api/HardwareController.php:84-96` — correct, normalized + parameterized:
  ```php
  if ($request->filled('search')) {
      $s = self::normalizeForSearch($request->search);
      $query->where(function ($q) use ($s) {
          $q->where('pc_name', 'LIKE', "%{$s}%")
            ->orWhere('hardwares.n_code', 'LIKE', "%{$s}%")
            // ...
            ->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$s}%"]);
      });
  }
  ```
  Note: `normalizeForSearch` escapes? Check `app/Traits/PersianNormalizer.php` — it only normalizes characters, not `%_` escaping. So escaping must be added separately.

- `app/Http/Controllers/Api/HardwareExportController.php:33-48` — buggy, no normalization, no escaping, also uses `whereHas('person', ...)` vs `join` variant:
  ```php
  if ($request->filled('search')) {
      $s = $request->search;
      $query->where(function ($q) use ($s) {
          $q->where('pc_name', 'LIKE', "%{$s}%")
              ->orWhere('n_code', 'LIKE', "%{$s}%")
              // ...
              ->orWhereHas('person', function ($pq) use ($s) {
                  $pq->where('f_name', 'LIKE', "%{$s}%")
                      ->orWhere('l_name', 'LIKE', "%{$s}%")
                      ->orWhereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$s}%"]);
              });
      });
  }
  ```

- `app/Traits/PersianNormalizer.php` — `normalizeForSearch(string $value): string` that does `ي→ی`, `ك→ک`, ZWNJ handling. Used widely (see `AGENTS.md` Persian Text Handling).

- `app/Http/Controllers/Api/PersonController.php:26-29` — also normalizes search correctly (good reference).

Repo conventions (AGENTS.md):

- **Persian Text Handling**: `PersianNormalizer::normalizeForSearch` must apply to *all* search/filter ops (Livewire + API) — currently `PersonController::index` and `HardwareController::index` do, export must too (`ي→ی`, `ك→ک`, ZWNJ→space).
- **Performance**: `pg_trgm` GIN indexes back `LIKE "%...%"` on `hardwares.(pc_name,comments,type,ip_*)` and `persons` full-name — normalization keeps trigram usage consistent between list and export.
- LIKE escaping: Laravel has no built-in; use `str_replace(['%','_'], ['\%','\_'], $s)` + keep `LIKE` without explicit `ESCAPE` (Postgres default with `standard_conforming_strings`). Document the choice.
- Keep filter parity between list and export (same fields: `search, type, os, cpu, ram, hdd, shutdown, net_type, mark, person, unit, semat`) — AGENTS.md **Development Guidelines #6** (org scope) and **UI Features > Export** (respect active filters).
- Code intelligence: `codegraph explore "HardwareExportController HardwareController"` before editing; export route is `GET /hardware/export` with `auth + role_or_permission:manage_hardware` (AGENTS.md **Safe Role/Permission**), not `auth:sanctum`.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 70e35c2..HEAD -- app/Http/Controllers/Api/HardwareExportController.php app/Traits/PersianNormalizer.php` | empty |
| Lint | `vendor/bin/pint --dirty --format agent` (AGENTS.md **Formatting**) | exit 0 |
| Tests | `composer test` — `config:clear + route:clear + XDEBUG_MODE=off php artisan test` (AGENTS.md **Running Tests**) | 884 baseline pass |
| Single file | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareExportTest.php` | pass (28 tests) |
| CodeGraph | `codegraph explore "HardwareExportController"` | symbols |

## Scope

**In scope**:

- `app/Http/Controllers/Api/HardwareExportController.php`
- `app/Traits/PersianNormalizer.php` (only if you add an `escapeLike` helper; otherwise no change)
- `tests/Feature/HardwareExportNormalizationTest.php` (create)

**Out of scope**:

- `app/Http/Controllers/Api/HardwareController.php` — don't change (reference impl)
- `app/Exports/HardwareExport.php` — column allow-list already correct
- Livewire component `resources/views/livewire/hardware/index.blade.php` — shares controller logic via query, not direct change
- Migrations

## Git workflow

- Branch: `advisor/005-export-normalization` (from `kimya`/`main`, AGENTS.md **New Features** flow)
- Commit: `fix(hardware): normalize Persian and escape LIKE wildcards in export`
- Do NOT push — plans only, no execution per user request
- Frontend: export is `GET /hardware/export` web route (session auth) but API-shaped — no Livewire single-file change; keep RTL layout untouched; no `npm run build` needed unless table columns changed

## Steps

### Step 1: Add `PersianNormalizer` to export controller and normalize all text filters

In `app/Http/Controllers/Api/HardwareExportController.php`:

1. Add `use App\Traits\PersianNormalizer;` and `use PersianNormalizer;` trait to class (like `HardwareController`).
2. For each text filter that currently does `$s = $request->search;` or `$normalized = $request->person;`, change to:
   ```php
   $s = self::normalizeForSearch($request->search);
   // and escape LIKE wildcards after normalization:
   $s = addcslashes($s, '%_'); // or str_replace(['%','_'], ['\%','\_'], $s)
   ```
   Apply to: `search`, `person`, `unit`, `semat`. For `type, os, cpu, ram, hdd, net_type`, also normalize (they are Persian-sensitive in `Hardware` saving hook: `Hardware.php:59` normalizes `pc_name, type, os, cpu, ram, hdd, net_type` on save). Use same normalization.

   For `orWhereRaw("CONCAT(...) LIKE ?", ["%{$s}%"])`, keep placeholder binding — the escaped value is safe.

3. Keep `LIKE "%{$s}%"` pattern; escaping ensures `%` in user input is literal.

**Verify**: `vendor/bin/pint --dirty --format agent` → 0. `XDEBUG_MODE=off php artisan test tests/Feature/HardwareExportTest.php` → still 28 passed.

### Step 2: Extract parity helper (optional, keep minimal)

If the filter block grows, consider extracting a trait method, but for S effort keep inline duplication with normalization added — just ensure the export's filter list matches `HardwareController::index:84-153` exactly (same 12 fields). Don't miss `typeAliases` handling which export already has at line 51-52 — keep it, but normalize after alias resolution.

Order matters: alias → normalize → escape.

```php
if ($request->filled('type')) {
    $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
    $type = $typeAliases[$request->type] ?? $request->type;
    $type = self::normalizeForSearch($type);
    $type = str_replace(['%','_'], ['\%','\_'], $type);
    $query->where('type', 'LIKE', "%{$type}%");
}
```

**Verify**: `composer test` still passes.

### Step 3: Write regression tests for export normalization parity

Create `tests/Feature/HardwareExportNormalizationTest.php` (Pest), modeled on `tests/Feature/HardwareExportTest.php:1-40`:

- Setup: create unit, person with Persian name containing `ي`/`ك`, hardware with `pc_name` containing Persian, user with `manage_hardware`, `actingAs` web session (export route is `GET /hardware/export` with `auth` guard, not sanctum — use `actingAs($user)` without sanctum, or test via `GET` with session). Check existing `HardwareExportTest` for how it authenticates export — it uses `actingAs($user)` for web guard.

- Cases:
  1. `export with Persian ye search finds normalized records` — create hardware `pc_name` with `ی` (U+06CC), search with `ي` (U+064A), export → contains the row. Same for `ك` vs `ک`.
  2. `export with percent wildcard is escaped` — create hardware `pc_name = "PC-100%"` and another `pc_name = "PC-100X"`, search `search=100%` → export contains only first, not second (proves `%` not acting as wildcard).
  3. `export filter parity with index` — same `search` returns same IDs via `GET /api/hardware?search=...` (sanctum) and via export (parse Excel or assert query count via controller). Simpler: test that export controller's query count matches index count for same search.

Use `Maatwebsite\Excel` fake or assert controller response is `200` and `Content-Type` is Excel; for content check, you can test the query directly: `app(HardwareExportController::class)->export(...)` is hard due to Excel download. Instead, test via HTTP and use `Excel::fake()` pattern seen in `HardwareExportTest`.

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/HardwareExportNormalizationTest.php` → 3 passed. `composer test` → full suite passes.

## Test plan

- New file `tests/Feature/HardwareExportNormalizationTest.php` with 3 tests.
- Pattern: `tests/Feature/HardwareExportTest.php` for Excel fake/assertDownload, `tests/Feature/HardwareApiTest.php` for search normalization tests.
- If Excel fake is brittle, at minimum test the query builder path by extracting a testable scope or by asserting the HTTP response succeeds and the DB query count matches.

## Done criteria

- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/HardwareExportNormalizationTest.php` exits 0
- [ ] `composer test` exits 0
- [ ] `app/Http/Controllers/Api/HardwareExportController.php` uses `PersianNormalizer` trait and all text filters call `normalizeForSearch` + `str_replace`/`addcslashes` for `%_`
- [ ] `grep -n "normalizeForSearch" app/Http/Controllers/Api/HardwareExportController.php` returns ≥4 matches
- [ ] No out-of-scope files modified
- [ ] `plans/README.md` row for 005 updated to DONE

## STOP conditions

- Export controller at `app/Http/Controllers/Api/HardwareExportController.php:14-107` does not match excerpt (drift — maybe export moved to Livewire action).
- `PersianNormalizer::normalizeForSearch` signature changed or trait not found — read `app/Traits/PersianNormalizer.php` and adapt.
- Existing `HardwareExportTest` fails after escaping because it searches with `%` expecting wildcard — inspect test, update expectation to escaped behavior, don't revert escaping.
- Export route `GET /hardware/export` auth changed from `auth` to `auth:sanctum` — check `routes/web.php:13` and adjust test auth accordingly.

## Maintenance notes

- If new filter fields are added to `HardwareController::index`, mirror them in `HardwareExportController::export` immediately — consider extracting a shared `HardwareFilterService` in a follow-up to eliminate duplication (deferred to keep this plan S).
- Reviewer: confirm escaping does not break `pg_trgm` index usage — escaped `\` still allows trigram index for the literal prefix; leading `%` still prevents index but that's inherent to `LIKE "%...%"`.
- Follow-up: add same escaping to `HardwareController::index` for consistency (currently not escaped there either — file a separate plan if desired).
