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
