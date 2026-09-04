# Plan 005: HardwareAuditModalLivewireTest

## Summary

Comprehensive Livewire test coverage for the `hardware.audit-modal` partial, tested through the `hardware.index` Livewire component.

## Test File

`tests/Feature/HardwareAuditModalLivewireTest.php`

## Tests (10)

| # | Test | Description |
|---|------|-------------|
| 1 | `test_guest_request_redirected` | Guests are redirected to `/login` |
| 2 | `test_unauthorized_user_gets_403` | Users without `manage_hardware` permission get 403 |
| 3 | `test_authorized_user_can_view` | Authorized users can load the hardware index page |
| 4 | `test_load_history_opens_modal` | `loadHistory()` sets `showHistoryModal`, `historyHardwareId`, populates `history` |
| 5 | `test_empty_history_message` | Empty history shows correct total/empty state |
| 6 | `test_filter_history_actions` | `filterHistory()` filters by action type (updated, deleted, rollback, null) |
| 7 | `test_pagination_controls` | Pagination works when `historyTotal > historyPerPage` |
| 8 | `test_rollback_button_visibility` | Rollback button present for updated entries with non-dash old values |
| 9 | `test_rollback_restores_field` | `rollbackHistoryField()` restores hardware field and creates rollback audit |
| 10 | `test_page_resets` | Page resets to 1 on `loadHistory()` and `filterHistory()` calls |

## Components Under Test

- `app/Traits/HardwareIndexHelpers.php` — `loadHistory()`, `filterHistory()`, `historyPage()`, `rollbackHistoryField()`
- `resources/views/livewire/hardware/audit-modal.blade.php` — Modal UI (filters, pagination, rollback buttons)

## Verification

All 10 tests pass with `XDEBUG_MODE=off php artisan test tests/Feature/HardwareAuditModalLivewireTest.php`.

## Status

✅ Complete — tests written, verified, formatted with Pint, committed to `bahar`.
