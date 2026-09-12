# Plan 001: Fix XSS via unescaped unit names in wire:click

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the STOP conditions occurs, stop and report — do not improvise.
>
> **Drift check (run first)**: `git diff --stat 5f9c24e..HEAD -- resources/views/livewire/tickets/`

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: HIGH
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5f9c24e`, 2026-09-12

## Why this matters
User-controlled unit names are interpolated directly into `wire:click` JavaScript string attributes without escaping. An attacker who creates a unit with a name like `'); alert(1);//` achieves stored XSS that fires for every user who opens the ticket create or inbox pages. This is a P1 because it is stored XSS — the payload persists in the database and executes in other users' browsers with no further interaction.

## Current state

**File 1**: `resources/views/livewire/tickets/⚡create.blade.php:218`
```blade
wire:click="selectUnit({{ $unit['id'] }}, '{{ $unit['name'] }}')"
```
The `{{ $unit['name'] }}` is inside a JS string literal. Blade's `{{ }}` escapes HTML entities (`<`, `>`, `&`, `"`, `'`) but NOT JavaScript injection vectors that break out of the single-quoted string. A name containing `'` breaks the string and injects code.

**File 2**: `resources/views/livewire/tickets/⚡inbox.blade.php:858`
```blade
<button type="button" wire:click="selectTargetUnit({{ $u['id'] }}, '{{ $u['name'] }}')"
```
Same pattern — `{{ $u['name'] }}` inside a JS string in a `wire:click` attribute.

**Non-issues confirmed**: `resources/views/livewire/tickets/⚡monitoring.blade.php:168` passes only the ID (`selectUnitForFilter({{ $u['id'] }})`) — no name interpolation, no vulnerability.

**Note**: The task description referenced `_form-create.blade.php:16` and `_form-edit.blade.php:11`, but these files do not exist in the repository (verified via `search_files`). Only the two files above are affected.

## Commands you will need
| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Drift check | `git diff --stat 5f9c24e..HEAD -- resources/views/livewire/tickets/` | No changes or only non-XSS-related changes |
| Verify fix | `grep -rn "wire:click.*selectUnit\|wire:click.*selectTargetUnit" resources/views/livewire/tickets/` | Both occurrences show `@js($unit['name'])` or `@js($u['name'])` |
| Verify no remaining raw interpolation | `grep -rn "wire:click.*'.*name.*'" resources/views/livewire/tickets/` | No results |
| Run tests | `composer test -- --filter="Ticket"` | All existing ticket tests pass |

## Scope
**In scope**: Two Blade files in `resources/views/livewire/tickets/` that interpolate unit names into `wire:click` attributes.
**Out of scope**: Other `wire:click` handlers that only pass IDs; the `⚡monitoring.blade.php` file (already safe); any other XSS vectors outside this pattern.

## Git workflow
- Branch: `advisor/001-fix-xss-wire-click`

## Steps

### Step 1: Create the feature branch
```bash
git checkout celin
git checkout -b advisor/001-fix-xss-wire-click
```
**Verify**: `git branch --show` → `advisor/001-fix-xss-wire-click`

### Step 2: Fix `⚡create.blade.php:218`
Replace the raw `{{ $unit['name'] }}` interpolation with `@js()`:

```blade
# BEFORE (line 218):
wire:click="selectUnit({{ $unit['id'] }}, '{{ $unit['name'] }}')"

# AFTER:
wire:click="selectUnit({{ $unit['id'] }}, @js($unit['name']))"
```

**Verify**: `sed -n '218p' resources/views/livewire/tickets/⚡create.blade.php` contains `@js($unit['name'])`

### Step 3: Fix `⚡inbox.blade.php:858`
Replace the raw `{{ $u['name'] }}` interpolation with `@js()`:

```blade
# BEFORE (line 858):
wire:click="selectTargetUnit({{ $u['id'] }}, '{{ $u['name'] }}')"

# AFTER:
wire:click="selectTargetUnit({{ $u['id'] }}, @js($u['name']))"
```

**Verify**: `sed -n '858p' resources/views/livewire/tickets/⚡inbox.blade.php` contains `@js($u['name'])`

### Step 4: Verify no remaining vulnerable patterns
```bash
grep -rn "wire:click.*'.*name.*'" resources/views/livewire/tickets/
```
**Verify**: No output (empty result — no more raw name interpolation in wire:click attributes).

### Step 5: Format and lint
```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse resources/views/livewire/tickets/⚡create.blade.php resources/views/livewire/tickets/⚡inbox.blade.php
```
**Verify**: No errors.

### Step 6: Run existing tests
```bash
composer test -- --filter="Ticket"
```
**Verify**: All ticket-related tests pass (no regressions).

### Step 7: Commit
```bash
git add resources/views/livewire/tickets/⚡create.blade.php resources/views/livewire/tickets/⚡inbox.blade.php
git commit -m "fix(security): escape unit names in wire:click via @js()

Prevent stored XSS where attacker-controlled unit names break out of
JS string literals in wire:click handlers on ticket create and inbox.
Uses Laravel @js() directive for proper JSON encoding."
```

## Test plan
1. Create a unit with a name containing a single-quote: `Test' onclick='alert(1)`
2. Open the ticket create page — the unit dropdown should render the name as text without executing injected JS
3. Open the ticket inbox page — same verification for the referral dropdown
4. Verify unit selection still works (the `selectUnit` and `selectTargetUnit` Livewire methods are called correctly)
5. Run `composer test -- --filter="Ticket"` — all tests pass

## Done criteria
- [ ] Both `wire:click` handlers use `@js()` for name interpolation
- [ ] No remaining `wire:click.*'.*name.*'` patterns in ticket views
- [ ] Existing tests pass
- [ ] Attack payload in unit name does not execute JavaScript

## STOP conditions
- If `@js()` directive is not available (Laravel version too old) — report and stop
- If fixing the Blade syntax causes a Livewire compilation error — report and stop
- If existing tests regress — report immediately

## Maintenance notes
- Any future Blade template that interpolates user data into `wire:click` JS strings must use `@js()`, never raw `{{ }}`
- Consider adding a PHPStan or Pint custom rule to flag `wire:click` attributes containing `{{` inside JS string contexts
- The `@js()` directive produces JSON-encoded output, which is safe for both JS strings and arguments
