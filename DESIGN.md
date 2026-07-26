# DESIGN.md — Design System & Component Inventory

This file documents the UI framework, component library, and design tokens used in the project.

## CSS Framework

- **Tailwind CSS v4** — utility-first CSS
- **DaisyUI v5** — Tailwind component plugin (themeable, accessible components)
- **maryUI v2.8** — Livewire component library built on DaisyUI (modals, tables, forms, etc.)

## Color Palette (DaisyUI themes)

- Primary: `emerald` (used for success/online states)
- Secondary: `orange` (warning/pending)
- Error: `red` (danger/offline)
- Info: `blue` (informational)
- Dark mode theme: `synthwave`
- Light mode theme: `fantasy`

## Component Inventory

| Component | Source | Usage |
|-----------|--------|-------|
| Modal / Dialog | maryUI `x-mary-modal` | Ticket create/edit, confirmations |
| Data Table | maryUI `x-mary-table` | Unit lists, person lists, ticket inbox |
| Form Inputs | maryUI `x-mary-input`, `x-mary-select` | All forms |
| Toggle | maryUI `x-toggle` | Filter controls on map pages |
| Alert / Toast | maryUI `x-mary-alert` | Success/error feedback |
| Avatar | maryUI `x-mary-avatar` | User profile, person lists |
| Badge | DaisyUI `badge` | Status indicators (ticket priority, todo completion) |
| Card | DaisyUI `card` | Dashboard widgets, reports |
| Chart | Highcharts | Network traffic (Zabbix), reports |
| Map | Leaflet.js | Interactive maps (GIS boundaries, location logs) |
| Header | maryUI `x-header` | Page headers with actions |
| Button | maryUI `x-button` | Actions throughout the app |
| Menu | maryUI `x-menu` | Sidebar navigation |

## Layout

- **App Layout**: `resources/views/components/layouts/app.blade.php` — sidebar navigation, full-width content
- **Auth Layout**: `resources/views/components/layouts/auth.blade.php` — login/register pages
- **Livewire Volt**: Single-file components with `<?php` block at top, Blade template below

## Icons

- **Heroicons** (via maryUI/DaisyUI) — primary icon set (e.g., `o-map-pin`, `o-home`)
- Custom SVG icons in `public/icons/` — unit type icons (network, health house, base, etc.)

## Typography

- **Vazirmatn** — Persian/Arabic font (loaded via `@font-face` in app layout)
- Fallback: system UI stack

## Responsive Breakpoints

Follows Tailwind defaults: `sm` (640px), `md` (768px), `lg` (1024px), `xl` (1280px), `2xl` (1536px).

## Map Architecture

- **Base map component**: `maps/map.blade.php` — reusable Leaflet map with private tile server
- **Embedded pattern**: Child components use `<livewire:maps.map/>` and access `window.map`
- **Standalone pattern**: Some maps create their own `L.map()` (interactive, units)
- **SPA navigation**: DOM polling waits for map container, checks `_leaflet_id` to avoid re-init

## Theming

- Theme selector via `data-theme` attribute on `<html>`
- Dark mode: `synthwave` theme
- Light mode: `fantasy` theme
- Sidebar uses `currentColor` SVG for theme adaptability
