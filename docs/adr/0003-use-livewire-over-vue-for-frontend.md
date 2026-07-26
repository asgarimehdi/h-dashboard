# ADR 0003: Use Livewire (PHP) over Vue (JS) for Frontend

## Status
Accepted

## Context
The team is primarily PHP-focused. The app needs reactive UIs (tables, modals, maps, forms) without a separate JS build pipeline for components.

Options considered:
1. **Livewire v4** (chosen) — server-rendered, PHP-first
2. Vue 3 + Inertia.js — requires JS build, separate state
3. Pure Blade + Alpine.js — limited reactivity for complex components

## Decision
Use Livewire v4 with maryUI component library for all interactive pages.

- All pages under `resources/views/livewire/` are Livewire components.
- Alpine.js used only for tiny client-side interactions (dropdowns, toggles).
- Vite builds only CSS (Tailwind) and minimal JS (Alpine, Leaflet).
- Livewire Volt for single-file components (PHP + Blade in one file).

## Consequences
- **Pros**: Single language (PHP), no API layer for UI, built-in validation, file uploads, polling.
- **Cons**: Server round-trip for every interaction; not ideal for high-frequency updates (use WebSockets/Reverb if needed).
- **Risk**: Payload size on large tables — use pagination + `withRelated` eager loading.
