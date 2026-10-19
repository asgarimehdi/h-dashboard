# perf: Leaflet and jalalidatepicker load globally and block every page

> **Plan 018** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1154 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/components/layouts/app.blade.php resources/views/livewire/maps resources/views/livewire/units resources/views/livewire/todo resources/views/livewire/reports resources/views/livewire/activity-log resources/views/livewire/tickets`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

`leaflet.js` (144 KB) and `jalalidatepicker.min.js` (20 KB) are loaded **globally, synchronously, in `<head>`, with no defer** — so both block parsing on every authenticated page. Measurement contradicts the in-file comment that they are "used by most pages": Leaflet is used by 5 views and jalalidatepicker by 6, out of 89 Blade views. Moving them to the pages that use them removes ~167 KB of parser-blocking JS from ~80% of page loads. Issue #984 already established the pattern for the other three libraries.

## Current state

- `resources/views/components/layouts/app.blade.php:20-34`:

```blade
{{-- Issue #984: leaflet.draw, leaflet-routing-machine and FullCalendar are
     page-specific. … Each page now pushes the library it needs into `@stack('scripts')`:
       maps/route + maps/route2 -> leaflet-routing-machine
       units/map                -> leaflet.draw
       todo/todo                -> full-calendar
     …
     Leaflet itself and jalalidatepicker stay global — they are used by most
     pages. Do not move a library back here without re-measuring. --}}
<script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
```

- `:17-18` — the two stylesheets stay (see out-of-scope).
- Actual usage (verified by grep on this commit):
  - Leaflet: `resources/views/livewire/map/map-dashboard.blade.php`, `maps/map.blade.php`, `maps/route.blade.php`, `maps/route2.blade.php`, `units/map.blade.php`.
  - jalalidatepicker (`jalaliDatepicker`/`startWatch`): `activity-log/index.blade.php`, `reports/advanced.blade.php`, `reports/todos.blade.php`, `tickets/⚡inbox.blade.php`, `tickets/⚡monitoring.blade.php`, `todo/todo.blade.php`.
- The `@stack('scripts')` pattern to copy exists in the pages pushed by #984 (e.g. `units/map.blade.php` pushes `leaflet.draw`).
- **Load-bearing constraints**: the static `import` of `map-store` in `bootstrap.js` and its `alpine:init` registration must not change (dynamic import → blank maps, issue #028); `resources/views/livewire/maps/map.blade.php` is the only place allowed to call `L.map()`; never put the Leaflet instance in Alpine reactive state.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/PageScriptSplittingTest.php tests/Feature/LeafletCssScopingTest.php` | all pass |
| Full suite (slow) | `composer test` | all pass |
| Map E2E | `bash scripts/e2e-test.sh tests/e2e/maps` (slow; needs `.env.e2e`) | specs pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/components/layouts/app.blade.php` (remove the two global `<script>` tags; update the comment)
- The 5 Leaflet views and the 6 datepicker views (add a `@push('scripts')`)
- `tests/Feature/PageScriptSplittingTest.php` / `LeafletCssScopingTest.php`

**Out of scope** (do NOT touch):
- `resources/js/bootstrap.js` / `resources/js/map-store.js` — the static import and `alpine:init` registration are required (#028).
- `resources/views/livewire/maps/map.blade.php`'s map creation.
- `resources/views/livewire/maps/route.blade.php` / `route2.blade.php`'s routing-machine pushes — leave them; only add the Leaflet core if it is no longer global and they do not already rely on a parent push.
- The Leaflet CSS link at `:17` — CSS is not parser-blocking the same way and #1018 deliberately scoped only the draw/routing CSS; leave `leaflet.css` alone unless the tests say otherwise.
- `units/map.blade.php`'s separate `window._drawnItems` instance (issue #702) — only add the push, change nothing else.

## Git workflow

- Branch: `perf/page-scoped-leaflet-datepicker`
- Commit style: `perf: load Leaflet and jalalidatepicker only where they are used (#018)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Enumerate the real consumers

For each library, list every view that needs it, including **transitive** ones (a Livewire child rendered inside a map page needs the library too — check `@include`/`<livewire:` usage). Update the usage counts with the greps above and record them in the PR. If a page embeds `livewire:maps.map`, the push must be on the **host page** (the child is not the one in `<head>`).

**Verify**: the list matches `grep -rl "leaflet" resources/views` and `grep -rl "jalaliDatepicker\|startWatch" resources/views` for the in-scope forms.

### Step 2: Add `@push('scripts')` to each consumer

Follow the #984 pattern exactly (same stack name, same placement after the library it depends on). Example for a datepicker page:

```blade
@push('scripts')
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
@endpush
```

Put the push **before** the page's own `@script`/inline initialisation so the global exists when it runs; if the page already defers its init to `@script`, keep that ordering.

**Verify**: `grep -rc "@push('scripts')" <each consumer>` ≥ 1, and the layout has no global push for these two.

### Step 3: Remove the globals and update the comment

Delete both `<script>` lines from the layout and rewrite the #984 comment so it no longer claims they are used by most pages — replace the justification with the actual counts.

**Verify**: `grep -n "leaflet.js\|jalalidatepicker.min.js" resources/views/components/layouts/app.blade.php` → no matches for script tags (the CSS link for leaflet remains).

### Step 4: Prove both the win and the absence of breakage

- Extend `PageScriptSplittingTest.php` to assert a non-map page's HTML does **not** reference `js/leaflet/leaflet.js` and a non-datepicker page's HTML does not reference the picker, while the consumers do.
- Run the map E2E specs to prove maps still draw (this is the real regression class — issue #028 was a blank-map bug).

**Verify**: focused tests pass; `bash scripts/e2e-test.sh tests/e2e/maps` → specs pass.

## Test plan

- Update `PageScriptSplittingTest.php`: for one map page and one datepicker page, assert the asset **is** present; for two pages that use neither (e.g. `dashboard`, `settings`), assert it is **absent**.
- Keep `LeafletCssScopingTest.php` green (unaffected if CSS is untouched).
- E2E: `tests/e2e/maps` must pass — markers must render after a sidebar navigation, not only on a direct load.
- Verification: focused PHP tests + the map E2E suite.

## Done criteria

- [ ] No global `leaflet.js` / `jalalidatepicker.min.js` script tag remains in the layout
- [ ] Every consumer page pushes the library; every non-consumer's HTML omits it (new/updated test proves both)
- [ ] `XDEBUG_MODE=off php artisan test tests/Feature/PageScriptSplittingTest.php tests/Feature/LeafletCssScopingTest.php` exits 0
- [ ] `bash scripts/e2e-test.sh tests/e2e/maps` passes
- [ ] `bootstrap.js`/`map-store.js` unchanged (`git diff --stat` shows nothing there)
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff

## STOP conditions

- A consumer is a **Livewire child** that cannot push into `<head>` (Livewire's `@push` may not reach the layout on a nested component update) — STOP and report; the push must go on the host page, which may change the plan's file list.
- A page uses the library only after a user interaction that happens post-load and the push ordering cannot guarantee it exists — report.
- Map E2E fails with blank maps after the change and the cause is not simple ordering.
- `grep` finds additional consumers beyond the lists above (the counts in this plan are wrong) — update the plan's premise before proceeding.

## Maintenance notes

- The layout comment now carries measured counts; any future "move a library back" must re-measure the same way (the comment instructs it).
- Reviewer should scrutinize: that no map page depends on another page's push, and that the datepicker still initialises on its six pages.
- Deferred: bundling these vendor libs through Vite instead of `public/js` — a larger build change.
