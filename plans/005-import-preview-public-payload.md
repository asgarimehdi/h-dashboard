# perf: the import preview keeps every parsed row in public Livewire state

> **Plan 005** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P1` · formerly GitHub issue #1141 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/hardware/import-hardware resources/views/livewire/kargozini/import-persons`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (pairs with the bulk-import plan; either order works)
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The whole parsed import preview — every row, every field diff, plus a per-row `wire:model` binding — lives in a **public Livewire property** and is rendered in full. The accepted upload size is 10 MB, so a large sheet can be tens of thousands of rows: every Livewire round trip while the preview is open (toggling one row, opening help, confirming) serializes the entire array, ships it, and re-renders the entire DOM. Bounding the preview to a paged window and keeping the row decisions server-side makes the page usable for large imports instead of freezing it.

## Current state

- `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php:19` — `public $previewData = [];`
- `:90` — the whole preview array is assigned to it after `Excel::import`:

```php
$this->previewData = $results['preview'] ?? [];
```

- `:92-95` — aggregate stats are derived with `count(array_filter($this->previewData, …))` (these must survive).
- `:182` — `foreach ($this->previewData as $index => $row)` builds the preview table.
- `:296-301` — per-row Livewire bindings:

```blade
wire:model="previewData.{{ $index }}.selected_action"
```

- `:129` — confirm re-imports the file and reads the selected actions via `$import->setSelectedActions($this->getSelectedActions())`.
- `resources/views/livewire/kargozini/import-persons/import-persons.blade.php:19,91,287-292` — identical shape.
- Repo conventions: single-file Livewire components; `#[Computed]` for derived/expensive values (see `resources/views/livewire/units/index.blade.php`); MaryUI components for tables/pagination; `WithPagination` for paged lists.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test <import livewire test>` (find: `grep -rl "preview" tests/Feature`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/livewire/hardware/import-hardware/import-hardware.blade.php`
- `resources/views/livewire/kargozini/import-persons/import-persons.blade.php`
- The import Livewire test file(s)

**Out of scope** (do NOT touch):
- `app/Imports/*.php` mapping/parsing logic — the rows you paginate must stay identical to what the import consumes.
- The upload/validation rules and the file-size limit.
- Any change to how `selected_action` is *interpreted* by the import.

## Git workflow

- Branch: `perf/import-preview-window`
- Commit style: `perf: page the import preview out of public Livewire state (#005)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Stop shipping every row

Replace `public $previewData` with:
- a **private** full preview stored server-side for the lifetime of the component — but note the preview is rebuilt from the file on confirm, so the simplest correct design is to keep only a **paged window** in public state plus a **sparse override map** `public array $rowActions = []` keyed by row number (only rows whose action the user changed).
- `#[Computed] ` properties for: the current page of rows, the aggregate stats (new/updated/unchanged/errors), and the total count.

Concretely, add a private/paged accessor for the parsed preview. If the component must keep the parse in memory, hold it in a **non-public** property (Livewire dehydrates only public properties — see the precedent in `users/index` where a private memo never enters the snapshot) and expose `#[Computed] previewPage()` returning only `LengthAwarePaginator`'s current slice (e.g. 50 rows).

**Verify**: `grep -n "public \$previewData" resources/views/livewire/hardware/import-hardware/import-hardware.blade.php` → no match.

### Step 2: Replace per-row bindings with a window + bulk actions

- Render one page of rows (MaryUI `x-table` with `with-pagination`, as the other list pages do).
- Per row, either keep a local Alpine selection (submitted in bulk) or a single `wire:change` that calls a component method with the row number — **not** `wire:model="previewData.N.selected_action"`.
- Add page-level actions ("select all on page → create/update/skip") that write into `$rowActions` in one call.
- Keep the existing stats display, fed by the computed aggregate.

**Verify**: `grep -n "previewData\." resources/views/livewire/hardware/import-hardware/import-hardware.blade.php` → no matches.

### Step 3: Feed confirm from the sparse map

`getSelectedActions()` must return `rowNumber => action` from `$rowActions`, defaulting to `auto` for untouched rows — the same semantics `setSelectedActions()` expects today. Confirm still re-imports the file, so nothing else changes.

**Verify**: focused import Livewire test passes (confirm still applies the chosen actions).

### Step 4: Mirror on `import-persons`

Apply the same shape to `resources/views/livewire/kargozini/import-persons/import-persons.blade.php`.

**Verify**: person-import Livewire tests pass.

## Test plan

- New tests in the import Livewire test file:
  1. With a preview of ~200 rows, `strlen($component->html())` is bounded (pick a generous ceiling) and the public snapshot does not contain all rows — assert via `$component->snapshot()` or by asserting the payload has no key holding 200 entries.
  2. Toggling a row's action persists that decision to `rowActions` and confirm applies it (assert on the **database** after confirm, not on component status).
  3. Untouched rows default to `auto` (assert the import result counts).
- Structural pattern: existing Livewire pagination tests (`tests/Feature/UsersManagementTest.php` uses `WithPagination` + snapshot-payload locks from #1040 — reuse that style).
- Verification: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Done criteria

- [ ] `grep -rn "public \$previewData" resources/views/livewire/hardware/import-hardware resources/views/livewire/kargozini/import-persons` returns no matches
- [ ] `grep -rn "previewData\." resources/views/livewire/hardware/import-hardware resources/views/livewire/kargozini/import-persons` returns no matches
- [ ] A 200-row preview test shows the public payload is bounded (new test)
- [ ] Confirm still applies per-row actions correctly (asserted against the database)
- [ ] `XDEBUG_MODE=off php artisan test <import tests>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- Livewire dehydrates the chosen "private" store anyway (i.e. it still appears in the snapshot) — STOP and report; a genuinely server-side store for a multi-request flow needs a cache/session handle instead, which is a different design.
- The import cannot reconstruct untouched rows as `auto` from the file alone (i.e. confirm depends on the full array, not just deltas) — STOP and report; that would change confirm semantics.
- The preview is rendered by a shared partial used elsewhere — report before changing it.

## Maintenance notes

- If a future change makes confirm not re-read the file (e.g. it consumes a server-side parsed store), the sparse `rowActions` contract must be revisited.
- Reviewer should scrutinize: that untouched rows still default to the same action they do today, and that the stats counts are unchanged.
- Deferred: streaming/chunked preview from disk — a larger redesign.
