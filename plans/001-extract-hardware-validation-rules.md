# 001 — Extract Hardware Validation Rules (DRY)

## Problem
`HardwareController::store()` and `update()` duplicate the same 17-field validation array (~30 lines each). Any new field must be added in two places, which is error-prone and violates DRY.

## Proposal
1. Create a private method `hardwareValidationRules(bool $required = true): array` in `HardwareController`.
2. `store()` calls it with `$required = true`; `update()` calls it with `$required = false` (all fields `nullable|sometimes`).
3. Update both methods to use the shared method.
4. Add a unit test that validates the rules array has the expected keys.

## Files
- `app/Http/Controllers/Api/HardwareController.php`
- `tests/Feature/HardwareApiTest.php`

## Risk: Low
Pure refactoring — no behavior change. All existing tests must pass unchanged.
