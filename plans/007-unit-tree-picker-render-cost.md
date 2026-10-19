# perf: the unit-tree picker rebuilds the whole tree on every render

> **Plan 007** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1143 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/partials/unit-tree-picker.blade.php resources/views/livewire/users/index.blade.php resources/views/livewire/kargozini/person.blade.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW-MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The unit-tree picker assembles its entire tree in PHP on **every** render — building HTML by string concatenation and embedding a flat array of every unit as inline JSON. The units table holds 834 rows in the current database, and the picker is included **unconditionally** inside a modal that is usually closed (`users/index`), and **twice per render** on `kargozini/person`. So ordinary interactions (typing in a search box, paging a table) rebuild and re-serialize an 834-element structure no one is looking at. Rendering the trigger until the modal opens makes the common path cheap.

## Current state

- `resources/views/livewire/partials/unit-tree-picker.blade.php:2-38` — the tree is built by PHP:

```php
$byParent = collect($units)->groupBy('parent_id');
$renderTree = function ($items) use (&$renderTree, $byParent) {
    $out = '';
    foreach ($items->sortBy('name')->values() as $u) { … $out .= '<div …>' … $out .= $renderTree($children); }
    return $out;
};
```

- `:58` — every unit is embedded as an inline JSON array:

```php
flat: {{ \Illuminate\Support\Js::from(collect($units)->map(fn ($u) => ['id' => $u['id'], 'name' => $u['name'], 'parent_id' => $u['parent_id'] ?? null])->values()->all()) }},
```

- Include sites:
  - `resources/views/livewire/users/index.blade.php:796` — inside `<x-modal wire:model="unitModal" …>`, so rendered even while the modal is closed.
  - `resources/views/livewire/kargozini/person.blade.php:494` and `:580` — two modals, both always rendered.
- `resources/views/livewire/users/index.blade.php:130-138` — `allUnitsTree()` is a `#[Computed]` method (#1040), so it is per-request, not per-render-cached across requests; the Blade include still re-runs each render.
- Repo conventions: single-file Livewire; `#[Computed]` for derived lists (`units/index.blade.php` is the exemplar); MaryUI `<x-modal wire:model="…">`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/UsersManagementTest.php tests/Feature/Kargozini/PersonLivewireTest.php` | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/livewire/partials/unit-tree-picker.blade.php`
- `resources/views/livewire/users/index.blade.php` (the include site only)
- `resources/views/livewire/kargozini/person.blade.php` (the two include sites only)
- The tests for those two pages

**Out of scope** (do NOT touch):
- The scoping contract in `allUnitsTree()`/`scopedAccessibleUnits()` (#849/#1040): the picker is the **write-target list**, built from `Unit::whereIn('id', $accessibleIds)`; an empty scope must stay an empty picker, never the whole org.
- The `unit-selected`/`u_id` write-validation logic on either page.
- `resources/views/livewire/unit/tree.blade.php` — that is the separate reusable read-only tree (issue #704), not this picker.
- The Alpine behaviour of the picker (search, expand, multi-select) — only *when* it is rendered changes.

## Git workflow

- Branch: `perf/unit-tree-picker-lazy`
- Commit style: `perf: render the unit-tree picker only when its modal opens (#007)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Render the picker markup only when the modal is open

In `users/index.blade.php`, wrap the include in the modal-open condition:

```blade
<x-modal wire:model="unitModal" title="انتخاب واحدها" persistent separator>
    @if ($unitModal)
        @include('livewire.partials.unit-tree-picker', [ … ])
    @endif
</x-modal>
```

When the modal opens (a Livewire property change), the same response renders the picker, so Alpine initialises it as usual. Do the same for both includes in `kargozini/person.blade.php` (`$filterUnitModal` and `$unitModal`).

**Verify**: `XDEBUG_MODE=off php artisan test tests/Feature/UsersManagementTest.php tests/Feature/Kargozini/PersonLivewireTest.php` → all pass.

### Step 2: Drop the duplicate render on `kargozini/person`

The two includes are distinct modals (a filter picker and a write-target picker) — keep both, but confirm each is inside its own `@if` so a closed modal costs nothing. If the filter picker can reuse the same `$units` payload, pass it once rather than recomputing.

**Verify**: `grep -c "unit-tree-picker" resources/views/livewire/kargozini/person.blade.php` → `2`, each inside an `@if`.

### Step 3: Lock the payload with a test

Add a test asserting that with the modal closed, the rendered HTML does **not** contain a marker unique to the picker (e.g. the `flat:` JSON or a known unit name from a seeded tree), and with the modal open it does. Also assert the picker still lists only in-scope units when open (empty scope → empty picker).

**Verify**: `XDEBUG_MODE=off php artisan test <those files>` → all pass.

## Test plan

- New tests:
  1. Modal closed → picker markup/JSON absent; modal open → present.
  2. Empty accessible scope → opening the picker shows no units (not every unit) — this is the #849 contract, keep it pinned.
  3. A seeded 200-unit tree, payload bounded check (optional but cheap: assert the closed payload is under a generous ceiling).
- Structural pattern: `tests/Feature/UsersManagementTest.php` (has snapshot-payload locks from #1040) and `tests/Feature/Kargozini/PersonLivewireTest.php`.
- Assertions on rendered output, not component internals.

## Done criteria

- [ ] With the modal closed, the rendered page contains no picker markup (new test)
- [ ] With the modal open, the picker renders and lists exactly the in-scope units
- [ ] Empty accessible scope → empty picker (test passes)
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/UsersManagementTest.php tests/Feature/Kargozini/PersonLivewireTest.php` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- A modal-open property is set server-side *before* the modal is shown in a way that would render the picker early — report which one.
- The picker's Alpine `init()` requires the element to exist at first paint (i.e. it does not initialise on a later Livewire update) — STOP and report; that would need an `@script`/event-based init instead.
- Any existing test asserts the closed-modal payload — report before adapting it.

## Maintenance notes

- New picker consumers should follow the `@if ($modalProperty)` include pattern; add a one-line comment in the partial's header noting it is render-on-open.
- Reviewer should scrutinize: that the picker still works on the **first** open (not just the second), and that multi-select for `users/index` is unaffected.
- Deferred: paginating or windowing the tree itself for very large unit tables.
