# Plan 010: MapsMapLivewireTest

## Objective
Add comprehensive Livewire test coverage for the `maps.map` embedded widget.

## Component
`resources/views/livewire/maps/map.blade.php` — anonymous-class Livewire component that renders a Leaflet map container.

## Tests Created
| # | Test | What it verifies |
|---|------|-----------------|
| 1 | `standalone_renders` | Component renders 200 with `id="map"`, `wire:ignore`, `h-[80lvh]`, `rounded` |
| 2 | `mount_sets_tile_template_to_osm_fallback` | Default tile URL is OpenStreetMap |
| 3 | `mount_sets_default_setview` | Default setview is `[36.558188, 48.716125]` |
| 4 | `mount_sets_default_zoom` | Default zoom is `'8'` |
| 5 | `config_override_changes_tile_template` | Config `map.tile_url_template` override is reflected |
| 6 | `parent_component_embeds_maps_map` | `maps.route` (which embeds `<livewire:maps.map/>`) renders `id="map"` |
| 7 | `blade_template_contains_script_assets` | Blade source has `initMap`, `invalidateSize`, `addEventListener`, `_leaflet_id`, `<style>` |
| 8 | `second_render_does_not_throw` | Two sequential renders succeed (SPA re-render safe) |

## File
`tests/Feature/MapsMapLivewireTest.php`

## Status
✅ All 8 tests passing (18 assertions, ~2s)
