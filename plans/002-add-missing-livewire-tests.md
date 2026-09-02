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

### Conventions (per AGENTS.md — must be followed)
- Livewire 4 **single-file components**: test by string dot-name (`Livewire::test('reports.advanced')`), no class import.
- Tests must be hermetic: no Redis (`CACHE_STORE=array` is forced in `phpunit.xml`), Postgres test DB `h_dashboard_test`.
- Zabbix-dependent components (`it.*`) — mock `ZabbixService` or expect graceful failure; never hit the real Zabbix URL.
- Respect organizational scope: authenticate a user + seed unit(s) via factories/seeders; map components (`maps.*`) need seeded lat/lng or boundary data.
- `maps.interactive` renders Leaflet in JS — assert only server-side state (mount data, markers payload), not DOM map rendering.
- Before writing `maps.*` tests, check `MapsVoltTest.php` for existing coverage to avoid duplication.

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
