# perf: help modal ships ~90 KB of hidden content on every Livewire update

> **Plan 002** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P1` · formerly GitHub issue #1138 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/components/help resources/views/components/layouts`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

The help modal includes **all 21** help-content sections unconditionally inside `<template x-if>` blocks. `resources/views/components/help/content/` is **88,933 bytes** across 21 files, and the modal is included by **24** Livewire components. Livewire returns the component's full rendered HTML on every update, so every keystroke, checkbox toggle, and pagination click in any of those 24 components resends this hidden static markup — on `users/index` it dwarfs the actual data payload. Only the selected section is ever displayed, so the rest is pure transfer cost.

## Current state

- `resources/views/components/help/modal.blade.php` — the modal; `:19`–`:79` compile every section body regardless of what is open:

```blade
<template x-if="helpSection === 'dashboard'">
    <div><x-help-content:dashboard /></div>
</template>
<template x-if="helpSection === 'hardware'">
    <div><x-help-content:hardware /></div>
</template>
{{-- …19 more, ~90 KB total… --}}
```

- `resources/views/components/help/content/` — 21 Blade files, 88,933 bytes total.
- Included inside Livewire-rendered trees, e.g. `resources/views/livewire/users/index.blade.php:621`, `resources/views/livewire/hardware/_toolbar.blade.php:8`; `grep -rl "help.modal" resources/views/livewire | wc -l` → `24`.
- The modal is opened via a Livewire property (`showHelpModal`, default `false`) and by an Alpine event: the wrapper listens for `help-open` and sets `$wire.showHelpModal = true` (see the top of `modal.blade.php`). Note the prop name is configurable (`wireModel`), defaulting to `showHelpModal`.
- Repo convention: components are single-file Livewire; help content lives under `resources/views/components/help/content/`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/AccessibilityInfrastructureTest.php` | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

> `AccessibilityInfrastructureTest.php` already asserts help-modal structure — read it before changing markup, and update it if the structure legitimately changes.

## Scope

**In scope**:
- `resources/views/components/help/modal.blade.php`
- `resources/views/components/help/content/*.blade.php` — **only** if you need to move content; prefer not touching them
- The test file(s) that assert the help modal (`grep -rl "help" tests/Feature`)

**Out of scope** (do NOT touch):
- Any Livewire component that includes the modal (`users/index`, `hardware/_toolbar`, …) — the fix is inside the modal itself.
- The help button component (`components/help/button.blade.php`) and the `help-open` event contract.
- The content of any help section.

## Git workflow

- Branch: `perf/help-modal-payload`
- Commit style: `perf: render help sections only when the modal is open (#002)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Gate the section bodies on the modal being open

Wrap the `<template x-if>` block (all 21 sections plus the "not available" fallback) so it only renders when the Livewire property is true. Use the component's `wireModel` prop, and keep the Alpine `helpSection` switching intact:

```blade
@php $wireModel = $wireModel ?? 'showHelpModal'; @endphp
...
@if ($wireModel === 'showHelpModal' ? $showHelpModal : $$wireModel)
    {{-- existing 21 <template x-if> blocks + fallback --}}
@endif
```

Because `help-open` sets `$wire.showHelpModal = true` (which is a Livewire update), the content will be rendered in the same response that opens the modal — no extra fetch and no visible delay. Verify this in the browser (step 3).

If `$showHelpModal` is not the actual property name on some consumers (the prop is overridable), handle it with a simple conditional on the prop name as above; do not hardcode `showHelpModal` for every caller.

**Verify**: `grep -c "x-help-content:" resources/views/components/help/modal.blade.php` → still 21 (bodies unchanged, just nested).

### Step 2: Prove the payload shrinks

Add a regression test rather than eyeballing:

- In the test file that covers `users/index` (`tests/Feature/UsersManagementTest.php`) or the accessibility test, render the page with `showHelpModal = false` and assert the response does **not** contain a distinctive string from a section other than the page's own (e.g. a heading unique to `help/content/maps.blade.php`), and with `showHelpModal = true` assert it **does**.
- Also assert a size ceiling: `strlen($component->html())` with the modal closed is materially smaller than with it open (pick a generous threshold, e.g. 20 KB below, so the test is not brittle).

**Verify**: `XDEBUG_MODE=off php artisan test <file>` → all pass.

### Step 3: Manual smoke check

Run the app locally (`composer dev` or `php artisan serve`) and open any page with a help button:
1. Click the help button → the correct section appears, no blank modal.
2. Switch sections via the in-modal switcher → content swaps as before.
3. Confirm no console error about `helpSection`.

## Test plan

- New/updated tests as described in step 2, in the file that already covers the help modal (`AccessibilityInfrastructureTest.php` is the closest existing home; otherwise `UsersManagementTest.php`).
- Structural pattern: existing Livewire feature tests using `InteractsWithTestSetup`; use `$this->withoutVite()` if the page renders `@vite` (see `tests/Feature/AccessibilityInfrastructureTest.php:40`).
- Note: per-repo rule, assert on rendered output/DOM, not merely on component status.

## Done criteria

- [ ] `grep -c "x-help-content:" resources/views/components/help/modal.blade.php` returns 21
- [ ] With the modal closed, a rendered page does not include another section's unique text; with it open, it does (new test proves both directions)
- [ ] `XDEBUG_MODE=off php artisan test <help/accessibility/users test files>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] No files outside the in-scope list are modified (`git status --porcelain`)

## STOP conditions

- `modal.blade.php` no longer contains the 21 sections or the `help-open` listener as excerpted (drift).
- Making content conditional breaks the "help section not available" fallback for an unknown section.
- Some consumer sets `helpSection` without opening the modal through the Livewire property (i.e. content is expected to exist while `showHelpModal` is false) — STOP and report which consumer, rather than guessing.

## Maintenance notes

- Adding a new help section now means adding a `<template x-if>` inside the gated block; the `grep -c` count in Done criteria will change from 21 to 22 and that is expected.
- Reviewer should scrutinize: that the modal still opens on the **first** click (the content arrives on the same response that flips the property), and that `helpSection` switching is unaffected.
- Deferred: moving help content fully out of the Livewire tree (e.g. a layout-level `@once` template) — bigger blast radius, not needed for the win.
