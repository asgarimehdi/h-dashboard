# Plan 50 step 4 — AGENTS.md sweep extension (issue #839)

`AGENTS.md` is write-protected on this Hermes surface (the `patch`/`write_file` tools refuse it and there is no
interactive approval channel on `api_server`), so the prepared edit is parked here. Apply it with a normal
editor or an interactive session.

## Where

`AGENTS.md` line **48** — the bullet beginning:

```
- **Never guard a unit scope with `->when($accessibleIds, fn ($q) => $q->whereIn(...))`**
```

There is a **second** place to touch: the Gotchas Quick Reference table has a row
(`when($scope)` on an empty array fails **open**, ~line 778). The step-4 ask is "extend the sweep and the rule",
so both the rule and the sweep command belong in the rule bullet.

## Replace line 48 with

```markdown
- **Never special-case an empty scope as "no restriction."** There are **three spellings**, and a sweep that only greps one of them will miss the other two (issue #839):

  | Spelling | Why it fails open |
  |---|---|
  | `->when($accessibleIds, fn ($q) => …)` | `Conditionable::when()` runs the callback only for a **truthy** value, so `[]` drops the predicate — the #819 leak in five `/reports/*` components |
  | `if (! empty($accessibleIds)) { $q->whereIn(…); }` | the predicate is simply never added — #839, all **8** sites in `PersonImport` / `HardwareImport` |
  | `if (! empty($accessibleIds) && ! in_array($id, $accessibleIds)) { reject }` | the **first conjunct is false**, so `&&` short-circuits and the row is **ACCEPTED** — the same bug with extra steps, and the most dangerous spelling because it *looks* like a scope check |

  `AccessibleUnitIds()` is legitimately `[]` for an account with no `user_units` row and no `person.u_id`, and such a user is deliberately allowed through `ValidateUnitContext`. **`[]` means "in scope of nothing", never "unrestricted".** Use a plain `->whereIn($column, $accessibleIds)` (or `->accessible($column)`) instead — an empty array compiles to `0 = 1`. The two-`when` form in `UnitsExportController` / `PersonsExportController` (`when($accessibleIds === [], whereRaw('1 = 0'))`) is also correct — but the unconditional one is one condition instead of two that must both stay right.
- **The sweep to run after any scope change** (covers all three spellings at once):
  ```bash
  grep -rnE '(when\(\s*\$[a-zA-Z]*[Ii]ds|! *empty\(\$[a-zA-Z]*[Ii]ds\))' app/ resources/views/
  ```
  A hit is only a leak if it **guards a scope predicate** — `empty($rootIds) return collect()` and
  `empty($deletedHardwareIds)` are early-returns, not scope bypasses. Still read each one: the two-`when`
  export form and `map-no-boundary`'s `:28` early-return are load-bearing.
```

## Verified sweep output on this branch

Run after the step-1 fix — 17 hits, **no remaining fail-open scope guard**. Reviewed each:

| File:line | Verdict |
|---|---|
| `app/Models/Unit.php:126` `empty($rootIds) return collect()` | early-return, not a scope bypass |
| `app/Models/Unit.php:133` `! empty($accessibleIds) array_intersect` | **out of scope for this plan** — `buildTree()` is called only from `units/chart.blade.php:72`, which already narrows `$rootIds` with `whereIn('id', $accessibleIds)`, so an empty scope yields no roots and the intersect is unreachable. Worth a follow-up issue. |
| `app/Models/Unit.php:137` `empty($allIds)` | early-return |
| `app/Traits/HardwareIndexHelpers.php:317` `empty($deletedHardwareIds)` | early-return |
| `app/Http/Controllers/Api/HardwareExportController.php:28` | fail-closed (`1 = 0`), reference impl |
| `resources/views/livewire/hardware/index.blade.php:46` | fail-closed `applyOrgScope` |
| `resources/views/livewire/reports/map-no-boundary.blade.php:28` | load-bearing early-return (documented in Gotchas) |
| `resources/views/livewire/units/index.blade.php:82` `! empty($accessibleIds) whereIn('id', …)` | **same fail-open shape as #839, not in this plan's 8 sites.** `/units` lists units; an empty-scope user would see all units. Flagging for a follow-up issue — fixing it is a one-line change but it is outside the plan's stated scope. |
| `resources/views/livewire/hardware/index.blade.php:394,455`, `units/index.blade.php:150,160`, jobs, commands | early-returns / non-scope filters |

## Why the tests had to be repaired, not deleted

Removing the 8 guards turned **23 of 37** tests red (7 in `PersonImportTest`, 6 in `PersonImportEdgeCasesTest`,
6 in `HardwareImportTest`, 4 in `HardwareImportEdgeCasesTest`) — matching the plan's audit. Those tests never
called `setAccessibleUnitIds()` and never authenticated, so they ran with an implicit empty scope and depended on
the fail-open path. They now set an explicit scope matching their own fixtures. The two
`import respects organizational scope` tests were left untouched — they are the meaningful ones.

Worth recording in AGENTS.md next to the sweep: **a test that exercises a scope consumer without setting a scope
is not a neutral test, it is a test that asserts the fail-open behaviour.**