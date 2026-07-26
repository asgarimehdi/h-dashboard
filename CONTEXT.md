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
| `manage_hardware` | Manage hardware inventory (شناسنامه سخت افزار) |
| `bw` | Access IT monitoring tools (networks, wireless, server cache) |

## Technical Vocabulary

| Term | Meaning |
|------|---------|
| **Access Service** | `AccessService::accessibleUnitIds()` — returns unit IDs a user can see (current unit + descendants via recursive CTE). |
| **Organizational Scope** | `HasOrganizationalScope` trait — adds `scopeAccessible()` to models to filter by accessible units. |
| **Unit Context** | `ValidateUnitContext` middleware — ensures `session('current_unit_id')` is set. |
| **Zabbix Service** | `ZabbixService` — wraps Zabbix API calls for network traffic monitoring. |
| **PersonUserFromDeviceSeeder** | Seeds users/persons/hardware from CSV device inventory data. |

## Abbreviations

| Abbrev | Full Term |
|--------|-----------|
| `n_code` | National code (person unique ID) |
| `u_id` | Unit ID (foreign key in persons) |
| `CTE` | Common Table Expression (recursive SQL) |
| `GIS` | Geographic Information System |
| `SRID` | Spatial Reference Identifier (4326 = WGS84) |
