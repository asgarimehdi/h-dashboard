# Health Dashboard — Data Model Reference

> Detailed data model for h-dashboard. See AGENTS.md for project rules.

## Data Model

### Core Entities

**Person** (`persons` table)
- `n_code` (PK, string)
- `f_name`, `l_name`
- `u_id` (FK to `units.id`)
- `s_id` (FK to `semats.id`) — job title (note: the column is **`s_id`**, not `semat_id`)
- `t_id` (FK to `tahsils.id`) — education level
- `e_id` (FK to `estekhdams.id`) — employment type
- `r_id` (FK to `radifs.id`) — rank/grade

**Hardware** (`hardwares` table)
- `id` (PK)
- `n_code` (FK to `persons.n_code`)
- `pc_name`, `type`, `os`, `ip_valid`, `ip_local`, `mac`
- `net_type`, `switch`, `port`, `shutdown` (boolean)
- `vlan`, `motherboard`, `cpu`, `ram`, `hdd`
- `comments`, `mark` (boolean)
- `clean_at` (nullable date)
- Relationship: `audits()` → `HardwareAudit` (field-level change audit trail)

**HardwareAudit** (`hardware_audits` table)
- `id`, `hardware_id` (no FK — audit trail survives hardware deletion), `user_id` (nullable FK), `action` (created, updated, deleted, bulk_mark, bulk_delete, force_deleted, rollback), `changes` (JSON: full attrs for created/deleted, `[{field, old, new}]` diff for updated), `source` (web, api, import, bulk), `ip_address`, `user_agent`
- Populated automatically by `HardwareAuditObserver` (registered in `AppServiceProvider`) — the single unified audit source (replaces the old `HardwareHistory` / `HardwareObserver` system)
- Indexes: `(hardware_id, created_at)`, `user_id`, `action`
- API: `GET /api/hardware/{hardware}/audits` (paginated, filterable) + alias `GET /api/hardware/{hardware}/history`; export, show, and rollback endpoints; UI: history modal with rollback on `/hardware` page

**Unit** (`units` table)
- `id`, `name`, `parent_id` (self-referencing for hierarchy), `lat`, `lng`, `unit_type_id`, `region_id`
- Indexes: `parent_id` (B-tree), composite `lat`+`lng` (B-tree), spatial indexes (PostGIS) on `boundaries`/`units`
- Relationships: `boundary` (hasOne), `children` (recursive), `parent`, `type`, `region`
- `Unit::ancestorIds()` resolves ancestor chains with a JOIN (performance fix)

**Region** (`regions` table)
- `id`, `name`, `parent_id` (self-referencing), `unit_type_id`
- Indexes: `parent_id` (B-tree)

**Province** (`provinces` table)
- `id`, `name` (geographic province, used by map features)

**UnitType** (`unit_types` table)
- `id`, `name`; allowed parent types constrained via `unit_type_relationships`

**Semat** (`semats` table)
- `id`, `name` (job titles)

**Ticket** (`tickets` table)
- `id`, `title`, `description`, `status`, `priority`, `assignee_id`, `unit_id`
- Relationships: `unit`, `task` (→ `todos`, `task_id`), `user`, `assignee` (`current_assignee_id`), `attachments`, `activities` (`task_activities`)
- Composite index on `(task_id, status)` (performance fix)
- Helpers: `canBeCompleted()`, `waitingDuration`, `statusName`

**Todo** (`todos` table)
- `id`, `title`, `start_at`, `end_at` (nullable), `is_completed`, `unit_id`, `user_id`
- `recurrence_rule` (`none`/`daily`/`weekly`/`monthly`, default `none`), `recurrence_interval` (default 1), `last_generated_at` (nullable) — drive `todos:generate-recurring`
- Helpers: `isRecurring()`, `nextOccurrence()` (Carbon based on `last_generated_at ?? start_at`)
- `tickets()` → `Ticket` (`task_id`)

**ActivityLog** (`activity_logs` table)
- `id`, `user_id`, `type`, `subject_type`, `subject_id`, `description`, `old_values`/`new_values` (json), `ip_address`, `user_agent`, timestamps
- User action audit trail (login/logout, CRUD), populated via `ActivityLogService`

**ActivityLogArchive** (`activity_log_archives` table)
- Mirror of `activity_logs` for cold storage. Adds `original_created_at`, `original_updated_at`, `archived_at`. Populated by `data:archive` (records older than 12 months).

**MaintenanceSchedule** (`maintenance_schedules` table)
- `id`, `unit_id` (FK, set null), `title`, `frequency` (`daily`/`weekly`/`monthly`), `recurrence_interval`, `last_generated_at`, `next_due_at`. Source for `maintenance:generate-due`.

**DailyReport** (`daily_reports` table)
- `id`, `unit_id` (FK, set null), `report_date`, `summary`, `payload` (json), `generated_by` (FK, set null), timestamps. One row per unit per day from `reports:generate-daily`.

**Notification** (`notifications` table, custom)
- `id`, `user_id`, `title`, `body`, `icon`, `color`, `url`, `is_read`, `created_at` — in-app notifications via `NotificationService` (cached bell queries)

**User** (`users` table)
- `id`, `n_code`, `name`, `email`, `password`
- BelongsToMany `units` via `user_units` pivot (with `role`, `is_primary`), `primaryUnit()`
- Spatie `HasRoles`

### Relationships

```
Hardware → Person (n_code)
Person → Unit (u_id → id)
Person → Semat (s_id → id)
Person → Tahsil (t_id → id)
Person → Estekhdam (e_id → id)
Person → Radif (r_id → id)
Unit → Unit (parent_id, recursive self-join)
Ticket → Todo (task_id)  Ticket → User (user_id / current_assignee_id)
Ticket → Attachment / TaskActivity
User ↔ Unit (user_units pivot)
```

### Key Linked Entities

- **User ↔ Person** are linked by `n_code` (not `id`). `Person.hasOne(User)` / `User.belongsTo(Person)`. `User` uses SoftDeletes; `User.units()` is a BelongsToMany via the `user_units` pivot (`role` enum: `responsible`/`staff`, `is_primary` flag); `primaryUnit()` returns the assignment where `is_primary = true`.
- **`user_units` pivot** — many-to-many user↔unit assignments (a user can belong to multiple units). Unique on `(user_id, unit_id)`. Seeded from `Person.u_id` via `UserUnitSeeder`.
- **Migration/style note:** FKs are explicitly named (e.g. `tickets_user_fk`, `ta_ticket_fk`). Migration count is **53**. New migrations follow `YYYY_MM_DD_######_description.php`; both a sequential counter (`000001`) and a time-suffixed form (`002725`) appear in the tree. Avoid the classic `YYYY_MM_DD_HHMMSS` Laravel default and pass `--no-interaction`.

## Area & Vocabulary

Definitions shared across codebase, docs, and team communication:

- **Person** — HR record in the directory, linked to a `User` one-to-one via `n_code`.
- **User** — authenticated account; Spatie roles/permissions; linked to Person via `n_code`.
- **Unit** — organizational unit (hospital, health center, county); tree via `parent_id`.
- **UnitType** — classification of a Unit; allowed parent types via `unit_type_relationships`.
- **Region** — hierarchical geographic division (province or county).
- **Boundary** — GIS polygon (MULTIPOLYGON, SRID 4326) representing a geographic area.
- **Location Log** — GPS point recorded by the mobile app (`location_logs`).

**Abbreviations:** `n_code` national code (person unique ID); `u_id` unit FK on persons; `CTE` common table expression (recursive SQL); `GIS` geographic information system; `SRID` spatial reference identifier (4326 = WGS84).

### FK Delete Behavior Summary

| FK | onDelete |
|----|----------|
| `users.n_code → persons` | restrict |
| `persons.e_id/t_id/s_id/r_id → lookups` | restrict |
| `units.region_id`, `units.parent_id` | restrict |
| `user_units.user_id → users` / `unit_id → units` | cascade |
| `tickets → task_activities`, `attachments` | cascade |
| `task_activities.activity_id → attachments` | cascade |
| `location_logs.user_id → users` | cascade |
| `todos.unit_id → units` | set null |
| `regions.boundary_id`, `units.boundary_id → boundaries` | cascade |
