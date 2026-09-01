# 002 — Add Missing Livewire Component Tests

## Problem
Several Livewire components have no test coverage. Based on CodeGraph analysis and file listing:

| Component | Has Test? |
|---|---|
| `it.networks` | ❌ (only `ItPagesTest` — page loads) |
| `it.wireless` | ❌ (only `ItPagesTest` — page loads) |
| `it.multi-gauge` | ❌ |
| `it.network-traffic-chart` | ❌ |
| `reports.advanced` | ❌ |
| `reports.map-no-boundary` | ❌ |
| `reports.persons` | ❌ |
| `maps.county` | ❌ (only `MapsPagesTest`) |
| `maps.interactive` | ❌ |
| `maps.point` | ❌ |
| `maps.route` / `maps.route2` | ❌ |
| `maps.unit` | ❌ |
| `notifications.bell` | Partial (`DashboardTodoNotificationTest`) |
| `glowingcard` | ❌ |

## Proposal
Add Livewire tests for the top-priority components:
1. `reports.advanced` — test filters, date range, data rendering
2. `maps.interactive` — test mount, layer toggle, marker display
3. `notifications.bell` — test mark-as-read, unread count
4. `it.networks` / `it.wireless` — test data loading, permission checks

Each test: `Livewire::test('component.name')->assertOk()` + functional assertions.

## Files
- `resources/views/livewire/reports/advanced.blade.php`
- `resources/views/livewire/maps/interactive.blade.php`
- `resources/views/livewire/notifications/bell.blade.php`
- `resources/views/livewire/it/networks.blade.php`
- `resources/views/livewire/it/wireless.blade.php`
- New: `tests/Feature/ReportsAdvancedLivewireTest.php`
- New: `tests/Feature/MapsInteractiveLivewireTest.php`
- New: `tests/Feature/NotificationsBellLivewireTest.php`
- New: `tests/Feature/ItNetworksLivewireTest.php`

## Risk: Low
Additive only — new test files, no production code changes.
