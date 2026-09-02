# 001 — Extract Hardware Validation Rules (DRY)

## Problem
`HardwareController::store()` and `update()` duplicate the same 17-field validation array (~30 lines each). Any new field must be added in two places, which is error-prone and violates DRY.

## Proposal
1. Create a private method `hardwareValidationRules(bool $required = true): array` in `HardwareController`.
2. `store()` calls it with `$required = true`; `update()` calls it with `$required = false`.
3. Update both methods to use the shared method.
4. Add a unit test that validates the rules array has the expected keys.

### ⚠️ Verified asymmetries in the current code (MUST be preserved)
- `update()` contains **`'shutdown' => 'boolean'`** which `store()` does NOT have. Decide explicitly:
  either the shared method includes `shutdown` for both (a small, likely-intentional behavior fix — confirm first),
  or the method takes the extra rule only for update. Default: keep behavior identical (shutdown only in update).
- `n_code` / `pc_name` are `required` in store but **`sometimes|required`** in update (not `nullable|sometimes` as the original plan said).
  The `$required` flag must map to `required` vs `sometimes|required`, not `nullable|sometimes`.
- The other 16 fields are identical `nullable|string|max:*` in both.

## Files
- `app/Http/Controllers/Api/HardwareController.php`
- `tests/Feature/HardwareApiTest.php`

## Risk: Low (was "Pure refactoring" — with the asymmetries above handled explicitly it stays low)
