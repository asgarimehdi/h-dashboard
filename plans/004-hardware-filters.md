# Plan 004: HardwareFiltersLivewireTest

## Goal
Add comprehensive Livewire test coverage for the `hardware.filters` partial, which is included by the `hardware.index` component.

## What was tested
The filters partial exposes 11 filter properties, quick preset buttons, a toggle for the advanced filter panel, and `clearFilters` / `loadDeletedHardware` actions. Since it's a Blade partial (not a standalone Livewire component), all tests go through `Livewire::test('hardware.index')`.

## Tests written (11)

| # | Test | Asserts |
|---|------|---------|
| 1 | `test_guest_request_redirected` | Guest → 302 redirect to /login |
| 2 | `test_unauthorized_user_gets_403` | User without `manage_hardware` → 403 |
| 3 | `test_authorized_user_can_view` | User with permission → 200 |
| 4 | `test_filters_hidden_by_default` | `showFilters` starts `false` |
| 5 | `test_toggle_panel` | `showFilters` toggles true/false via `set()` |
| 6 | `test_toggle_filter_set` | `toggleFilter('filterType', 'laptop')` sets value |
| 7 | `test_toggle_filter_off` | Same value again → clears to `null` |
| 8 | `test_clear_filters` | All 11 properties reset to `null` |
| 9 | `test_has_active_filters` | Active-filter indicator appears/disappears |
| 10 | `test_deleted_view` | `loadDeletedHardware` opens `showTrashModal` |
| 11 | `test_combined_filters` | type+ram+shutdown narrow visible hardware |

## Key decisions
- Used Pest test style (not class-based) to match project conventions
- `toggle` is a Livewire Blade magic, not a public method → used `set('showFilters', ...)` instead
- `hasActiveFilters()` is a method (not property) → asserted via rendered "فیلترهای فعال اعمال شده" indicator text
- `assertSeeLivewire` not applicable for Blade partials → used property/content assertions

## Files created
- `tests/Feature/HardwareFiltersLivewireTest.php`
- `plans/004-hardware-filters.md` (this file)
