# perf: hardware row selection and the n_code lookup fire a round trip each

> **Plan 010** · from the read-only performance audit at commit `56d794a2` (2026-10-19).
> Labels: `improve, perf, P2` · formerly GitHub issue #1146 (this file is the source of truth).

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If any STOP condition occurs, stop and report — do not improvise. This plan is self-contained; the audit session that produced it is not available to you.

**Drift check (run first)**: `git diff --stat 56d794a2..HEAD -- resources/views/livewire/hardware/table.blade.php resources/views/livewire/hardware/_form-create.blade.php`

## Status

- **Priority**: P2
- **Effort**: S-M
- **Risk**: LOW-MED
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `56d794a2`, 2026-10-19

## Why this matters

Two hardware-page interactions each trigger a full Livewire round trip that re-renders the entire page (table + filters + help modal): ticking a row checkbox sends the selection with `wire:model.live`, so selecting N rows costs N component updates; and the national-code field uses `wire:model.live` with **no debounce**, so every character typed fires a request and a person lookup. Both are cheap to fix and neither changes behaviour. (The ticket-create search at `tickets/⚡create.blade.php:273` is already `.debounce.300ms` — leave it alone.)

## Current state

- `resources/views/livewire/hardware/table.blade.php:48-56` — per-row live binding:

```blade
<x-table …>
    @scope('cell_checkbox', $hw)
        <input type="checkbox" wire:model.live="selected" value="{{ $hw['id'] }}" class="checkbox checkbox-sm"
            aria-label="انتخاب سخت افزار {{ $hw['pc_name'] }}" />
    @endscope
```

- `resources/views/livewire/hardware/_form-create.blade.php:6` — live, no debounce:

```blade
<x-input wire:model.live="n_code" label="کد ملی / نام پرسنل" placeholder="جستجو..." />
```

- `resources/views/livewire/hardware/index.blade.php:129-135` — `n_code` drives `n_code_status`/`n_code_name`/`n_code_unit`/`personResults`, resolved on update; `:206` is where the lookup runs.
- Repo convention/gotcha: **person search debounce is 500 ms and must not be removed** (it exists to stop update floods). Match that for `n_code`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Focused tests | `XDEBUG_MODE=off php artisan test tests/Feature/HardwareIndexTest.php tests/Feature/HardwareCrudTest.php` (find the actual files: `ls tests/Feature | grep -i hardware`) | all pass |
| Full suite (slow) | `composer test` | all pass |
| Format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` | no new errors |

## Scope

**In scope**:
- `resources/views/livewire/hardware/table.blade.php`
- `resources/views/livewire/hardware/_form-create.blade.php`
- The hardware Livewire tests

**Out of scope** (do NOT touch):
- `resources/views/livewire/tickets/⚡create.blade.php:273` — already debounced.
- The bulk-selection semantics server-side (`$selected` handling, bulk action authorisation) — only the *binding mode* changes.
- The person-lookup query itself.

## Git workflow

- Branch: `perf/hardware-interaction-round-trips`
- Commit style: `perf: defer hardware row selection and debounce the n_code lookup (#010)`
- Do not push or open a PR unless instructed.

## Steps

### Step 1: Defer the row checkbox

Drop `.live` so the checkbox only updates on a subsequent explicit action, and make the bulk actions read the current selection:

```blade
<input type="checkbox" wire:model="selected" value="{{ $hw['id'] }}" … />
```

`wire:model` (deferred) sends the value with the next request, so a bulk action (`wire:click="…"`) picks up the full selection in **one** round trip. If any code reads `$selected` during the same render as the tick, that read must move to the action — verify by reading the bulk handlers.

Alternative if deferred binding proves awkward: keep `wire:change` on the checkbox calling a single component method. Prefer the deferred form (less code).

**Verify**: `XDEBUG_MODE=off php artisan test <hardware tests>` → all pass, including bulk selection tests.

### Step 2: Debounce the national-code field

```blade
<x-input wire:model.live.debounce.500ms="n_code" label="کد ملی / نام پرسنل" placeholder="جستجو..." />
```

500 ms matches the documented person-search debounce.

**Verify**: `grep -n "n_code" resources/views/livewire/hardware/_form-create.blade.php` → shows `.debounce.500ms`.

### Step 3: Add a round-trip regression test

Add a test that selecting two rows and invoking the bulk action produces the expected database effect in a way that does not depend on N intermediate renders — e.g. `->set('selected', [1,2])->call('bulkAction')` then assert the DB. (This pins that deferred binding still delivers the selection.)

**Verify**: `XDEBUG_MODE=off php artisan test <file>` → all pass.

## Test plan

- New/updated tests in the hardware Livewire test file:
  1. Bulk selection via `->set('selected', [ids])` + action → database effect (assert on the **database**, never component status — repo rule).
  2. `n_code` lookup still resolves a valid person after the debounce change (call `->set('n_code', '…')` → `n_code_status === 'valid'`).
- Structural pattern: existing hardware Livewire tests.
- Note: a Livewire test cannot easily assert "fewer requests"; do not attempt it — the binding change is verified by the DOM attribute (`grep`) plus behaviour tests.

## Done criteria

- [ ] `grep -n "wire:model.live=\"selected\"" resources/views/livewire/hardware/table.blade.php` returns no match
- [ ] `grep -n "debounce.500ms" resources/views/livewire/hardware/_form-create.blade.php` returns one match
- [ ] Bulk selection and `n_code` lookup tests pass
- [ ] `XDEBUG_MODE=off php artisan test <hardware tests>` exits 0
- [ ] `vendor/bin/pint --dirty --format agent` leaves no diff
- [ ] `vendor/bin/phpstan analyse --no-progress` reports no new errors

## STOP conditions

- A bulk action reads `$selected` **during** the tick render (not inside its own action), so deferring breaks it — report the call site before changing the binding.
- The checkbox is bound to a nested array path that deferred binding cannot carry — report the actual binding.
- Any existing test asserts the `.live` attribute.

## Maintenance notes

- If row selection ever needs an immediate visual count, use client-side Alpine for the label and keep the server binding deferred; do not reintroduce `.live` on the checkbox.
- Reviewer should scrutinize: that bulk actions still see the full selection, and that `n_code` validation feedback still appears promptly after 500 ms.
