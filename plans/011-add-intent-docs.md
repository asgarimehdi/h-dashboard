# Plan 011: Add intent documentation (ADR/PRD/CONTEXT.md)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat <planned-at SHA>..HEAD -- docs/ CONTEXT.md DESIGN.md PRODUCT.md PRD.md`
> If any intent doc changed since this plan was written, compare the
> "Current state" against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: commit `b585f88`, 2024-07-17
- **Issue**: N/A

## Why this matters

The repo has **zero** intent/design documentation:
- No ADRs (Architecture Decision Records) in `docs/adr/` or `docs/adrs/`
- No `CONTEXT.md` for shared domain vocabulary
- No `DESIGN.md` for design-system spec
- No `PRODUCT.md` / `PRD.md` for product brief

This makes it impossible for new contributors (or future agents) to understand *why* certain architectural choices were made (e.g., recursive CTE for hierarchy, Spatie permissions, Livewire over Vue, maryUI components, Zabbix integration). Adding minimal docs captures intent and grounds future direction suggestions.

## Current state

**Glob results** (ran during audit):
```
find . -name "*.md" -o -name "*.txt" | grep -E "(adr|decision|design|context|product|prd)"
```
→ **No matches** (excluding node_modules)

**Relevant existing docs:**
- `README.md` — basic setup, Persian language
- `AGENTS.md` — tech stack snapshot (incomplete, per plan 010)
- `CLAUDE.md` — agent instructions
- `opencode.md` — agent instructions (duplicate of CLAUDE.md?)

## Commands you will need

| Purpose   | Command                  | Expected on success |
|-----------|--------------------------|---------------------|
| Create    | `mkdir -p docs/adr`      | exit 0 |
| Lint      | `vendor/bin/pint --test` | `All files pass` |

## Scope

**In scope** (the only files you should create/modify):
- `CONTEXT.md` (create) — shared domain vocabulary
- `DESIGN.md` (create) — design system/components used
- `docs/adr/0001-use-recursive-cte-for-unit-hierarchy.md` (create)
- `docs/adr/0002-use-spatie-permissions-for-access-control.md` (create)
- `docs/adr/0003-use-livewire-over-vue-for-frontend.md` (create)
- `docs/adr/0004-integrate-zabbix-for-network-monitoring.md` (create)

**Out of scope** (do NOT touch, even though they look related):
- `README.md` — keep as-is
- `AGENTS.md` — separate plan 010
- `CLAUDE.md` / `opencode.md` — agent-specific, not intent
- Any source code files

## Git workflow

- Branch: `advisor/011-add-intent-docs` (or the repo's branch-naming convention if one is evident)
- Commit per file or per logical unit; message style: `docs: add CONTEXT.md and ADRs for key architectural decisions`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Create `CONTEXT.md`

Create the file with the following content (based on `AGENTS.md` and code audit):

```markdown
# CONTEXT.md — Shared Domain Vocabulary

This file defines the canonical terms used across the codebase, docs, and team communication.

## Core Domain Terms

| Term | Definition | Related Models / Tables |
|------|------------|-------------------------|
| **Person** | A human being in the organizational directory (HR record). Linked to a User via `n_code`. | `persons`, `users` |
| **User** | An authenticated account that can log in. One-to-one with Person via `n_code`. Has roles/permissions (Spatie). | `users`, `user_units` |
| **Unit** | An organizational unit (department, clinic, hospital, etc.). Forms a tree via `parent_id`. | `units`, `unit_types`, `regions` |
| **Unit Type** | Classification of a Unit (e.g., "Hospital", "Health Center", "County"). Defines allowed parent types. | `unit_types`, `unit_type_relationships` |
| **Region** | Geographic administrative division (province or county). Hierarchical. | `regions`, `boundaries` |
| **Boundary** | GIS polygon (MULTIPOLYGON, SRID 4326) representing a geographic area. | `boundaries` |
| **Ticket** | A task/issue created by a User in a Unit. Can be forwarded, assigned, accepted, completed. | `tickets`, `task_activities`, `attachments` |
| **Todo** | A personal or unit-level scheduled task. Belongs to a Unit (nullable). | `todos` |
| **Task Activity** | An audit trail event on a Ticket (forward, accept, complete, etc.). | `task_activities` |
| **Attachment** | A file uploaded to a Ticket or Task Activity. | `attachments` |
| **Location Log** | GPS point recorded by a mobile user (Flutter app). | `location_logs` |
| **Notification** | In-app notification sent to a User (e.g., new ticket assigned). | `notifications` |

## Permission Vocabulary

| Permission | Meaning |
|------------|---------|
| `manage_users` | Full CRUD on users |
| `organization` | View/modify units, unit types, regions |
| `kargozini` | Manage HR lookup tables (estekhdam, tahsil, semat, radif, persons) |
| `map` | Access map features (GIS, location logs) |
| `calendar` | Access todo/calendar features |
| `view_all_tickets` | See tickets across all accessible units |
| `create_ticket` | Create a new ticket |
| `view_assigned_tickets` | See tickets assigned to user |
| `manage_roles` | Manage Spatie roles/permissions |
| `op-cache` | Access OPcache GUI at `/op` |

## Technical Vocabulary

| Term | Meaning |
|------|---------|
| **Access Service** | `AccessService::accessibleUnitIds()` — returns unit IDs a user can see (current unit + descendants via recursive CTE). |
| **Organizational Scope** | `HasOrganizationalScope` trait — adds `scopeAccessible()` to models to filter by accessible units. |
| **Unit Context** | `ValidateUnitContext` middleware — ensures `session('current_unit_id')` is set. |
| **Zabbix Service** | `ZabbixService` — wraps Zabbix API calls for network traffic monitoring. |

## Abbreviations

| Abbrev | Full Term |
|--------|-----------|
| `n_code` | National code (person unique ID) |
| `u_id` | Unit ID (foreign key in persons) |
| `CTE` | Common Table Expression (recursive SQL) |
| `GIS` | Geographic Information System |
```

**Verify**: File exists at `CONTEXT.md`

### Step 2: Create `DESIGN.md`

Create the file with the following content:

```markdown
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

See `tailwind.config.js` for exact theme extension.

## Component Inventory

| Component | Source | Usage |
|-----------|--------|-------|
| Modal / Dialog | maryUI `x-mary-modal` | Ticket create/edit, confirmations |
| Data Table | maryUI `x-mary-table` | Unit lists, person lists, ticket inbox |
| Form Inputs | maryUI `x-mary-input`, `x-mary-select` | All forms |
| Alert / Toast | maryUI `x-mary-alert` | Success/error feedback |
| Avatar | maryUI `x-mary-avatar` | User profile, person lists |
| Badge | DaisyUI `badge` | Status indicators (ticket priority, todo completion) |
| Card | DaisyUI `card` | Dashboard widgets, reports |
| Chart | Leaflet / custom | Network traffic (Zabbix), unit distribution |
| Map | Leaflet.js | Interactive maps (GIS boundaries, location logs) |

## Layout

- **App Layout**: `resources/views/components/layouts/app.blade.php` — sidebar navigation, header, footer
- **Auth Layout**: `resources/views/components/layouts/auth.blade.php` — login/register pages
- **Livewire Pages**: `resources/views/livewire/**` — each feature is a Livewire component

## Icons

- **Heroicons** (via maryUI/DaisyUI) — primary icon set
- Custom SVG icons in `public/icons/` — unit type icons (hospital, health house, etc.)

## Typography

- **Vazirmatn** — Persian font (loaded via Vite, `public/build/assets/Vazirmatn-*.woff2`)
- Fallback: system UI stack

## Responsive Breakpoints

Follows Tailwind defaults: `sm` (640px), `md` (768px), `lg` (1024px), `xl` (1280px).

## Dark Mode

Not currently implemented. DaisyUI supports `data-theme="dark"` — future work if requested.
```

**Verify**: File exists at `DESIGN.md`

### Step 3: Create ADR 0001 — Recursive CTE for Unit Hierarchy

Create `docs/adr/0001-use-recursive-cte-for-unit-hierarchy.md`:

```markdown
# ADR 0001: Use Recursive CTE for Unit Hierarchy Queries

## Status
Accepted

## Context
The `units` table uses a self-referential `parent_id` to form a tree (depth up to ~5 levels: Province → County → Hospital → Health Center → Health House). We need to answer "all descendants of unit X" for hierarchical access control.

Options considered:
1. **Adjacency list + recursive CTE** (chosen)
2. Materialized path (e.g., `path` column with `1.2.3`)
3. Nested sets (left/right indices)
4. Closure table (separate `unit_ancestors` table)

## Decision
Use MySQL 8.0+ / PostgreSQL recursive CTE via `Unit::descendantIds()` for on-demand descendant resolution.

- Query runs in ~1-5ms for typical trees (<5000 units).
- Results cached for 15 minutes per input set (see `Unit::descendantIds()` cache key).
- No write overhead on unit creation/move (unlike materialized path / nested sets / closure table).

## Consequences
- **Pros**: Simple schema, no write amplification, ACID-safe, portable across MySQL/PostgreSQL.
- **Cons**: Read-time cost; not suitable for very deep or very large trees (not our case).
- **Risk**: If unit count grows >50k, consider migrating to closure table.

## Implementation
- `app/Models/Unit.php::descendantIds()` — recursive CTE
- `app/Services/AccessService.php::accessibleUnitIds()` — caches result per user/session
- Middleware `ValidateUnitContext` — sets `session('current_unit_id')` for scope resolution
```

### Step 4: Create ADR 0002 — Spatie Permissions

Create `docs/adr/0002-use-spatie-permissions-for-access-control.md`:

```markdown
# ADR 0002: Use Spatie Laravel Permission for Functional Access Control

## Status
Accepted

## Context
The app needs fine-grained permissions (e.g., `create_ticket`, `view_all_tickets`, `map`, `calendar`) that are role-based but also assignable to individual users.

Options considered:
1. **Spatie Laravel Permission** (chosen)
2. Laravel Gates/Policies only
3. Custom RBAC package

## Decision
Use `spatie/laravel-permission` v8 for role/permission management.

- Roles: `admin`, `manager`, `staff`, `viewer`
- Permissions: functional (what you can do)
- Data scope: handled separately by `AccessService` (which units' data you see)

## Consequences
- **Pros**: Battle-tested, supports teams/guards, integrates with Sanctum, blade directives (`@can`, `@role`).
- **Cons**: Adds `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` tables.
- **Risk**: Permission bloat — keep permissions coarse; use data scope for fine-grained filtering.
```

### Step 5: Create ADR 0003 — Livewire over Vue

Create `docs/adr/0003-use-livewire-over-vue-for-frontend.md`:

```markdown
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

## Consequences
- **Pros**: Single language (PHP), no API layer for UI, built-in validation, file uploads, polling.
- **Cons**: Server round-trip for every interaction; not ideal for high-frequency updates (use WebSockets/Reverb if needed).
- **Risk**: Payload size on large tables — use pagination + `withRelated` eager loading.
```

### Step 6: Create ADR 0004 — Zabbix Integration

Create `docs/adr/0004-integrate-zabbix-for-network-monitoring.md`:

```markdown
# ADR 0004: Integrate Zabbix for Network Traffic Monitoring

## Status
Accepted

## Context
The IT operations team needs to visualize network interface traffic (in/out) for monitored devices. Zabbix is already deployed in the org.

Options considered:
1. **Direct Zabbix API calls** (chosen) — `ZabbixService` wraps JSON-RPC
2. Zabbix sender / trapper — push from app to Zabbix (wrong direction)
3. Export Zabbix data to separate TSDB (InfluxDB) — overkill

## Decision
Call Zabbix JSON-RPC API via `ZabbixService` from Laravel controllers.

- `TrafficController` — interface traffic charts (cached 30 min)
- `MultiLatestValueController` — latest values for multiple items (cached 20 min)
- Auth via Bearer token in `config/services.php`

## Consequences
- **Pros**: Reuses existing Zabbix investment, no new infra.
- **Cons**: Coupled to Zabbix API version; network latency on API calls (mitigated by caching).
- **Risk**: Zabbix API changes — wrap all calls in `ZabbixService` for single point of update.
```

### Step 7: Verify all files created

```bash
ls -la CONTEXT.md DESIGN.md docs/adr/
```

**Verify**: All 6 files exist with content.

## Test plan

- No tests needed — documentation only.
- Validation: `vendor/bin/pint --test` (no PHP files, but run for completeness).

## Done criteria

ALL must hold:

- [ ] `CONTEXT.md` exists with domain vocabulary table
- [ ] `DESIGN.md` exists with component inventory
- [ ] `docs/adr/0001-use-recursive-cte-for-unit-hierarchy.md` exists
- [ ] `docs/adr/0002-use-spatie-permissions-for-access-control.md` exists
- [ ] `docs/adr/0003-use-livewire-over-vue-for-frontend.md` exists
- [ ] `docs/adr/0004-integrate-zabbix-for-network-monitoring.md` exists
- [ ] `vendor/bin/pint --test` exits 0
- [ ] No source code files modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `docs/adr/` directory cannot be created.
- A step's verification fails twice after a reasonable fix attempt.
- The fix appears to require touching an out-of-scope file.

## Maintenance notes

- When making architectural changes, add/update the corresponding ADR.
- Keep `CONTEXT.md` and `DESIGN.md` in sync with code changes.
- ADR format follows [Michael Nygard's template](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).