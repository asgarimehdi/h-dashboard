# Database Performance Optimization Plan

## Executive Summary
This plan addresses database performance issues identified in the `new` branch of the h-dashboard project. The audit focused on missing indexes, potential N+1 query patterns, and inefficient queries.

## Findings

### Finding 1: Missing Indexes on Foreign Keys and Query Columns
- **Category**: Performance
- **Impact**: HIGH - Slow queries on large datasets, full table scans on joins
- **Effort**: M (migration + verification)
- **Risk**: LOW - Read-only index additions
- **Confidence**: HIGH - Identified by schema analysis
- **Evidence**: 
  - `persons.u_id` - foreign key to units, no index
  - `persons.n_code` - used in User relationship, no index
  - `users.n_code` - foreign key to Person, no index
  - `todos.created_at`, `todos.updated_at` - timestamp filtering, no indexes
  - `tickets.created_at`, `tickets.updated_at` - timestamp filtering, no indexes
  - `user_units.user_id`, `user_units.unit_id` - pivot table foreign keys
  - `user_unit_todo` pivot table foreign keys

### Finding 2: HasOrganizationalScope trait lacks eager loading
- **Category**: Performance
- **Impact**: MEDIUM - Potential N+1 queries when using accessible() scope
- **Effort**: S
- **Risk**: LOW
- **Confidence**: MEDIUM - Trait code review
- **Evidence**: 
  - `app/Traits/HasOrganizationalScope.php:10-15` - applies whereIn without eager loading relationships
  - Used in Todo, Ticket, Person models
  - Controllers like TodoController use `Todo::accessible()` without `with()`

### Finding 3: Unit descendantIds uses recursive CTE per call
- **Category**: Performance
- **Impact**: MEDIUM - Recursive CTE on every call, no caching of full tree
- **Effort**: M
- **Risk**: MEDIUM - Changes to core hierarchy logic
- **Confidence**: MEDIUM
- **Evidence**: 
  - `app/Models/Unit.php:75-97` - `descendantIds()` runs recursive CTE query each invocation
  - Called by AccessService::accessibleUnitIds() which is cached but cache invalidated on context change

### Finding 4: NotificationService::notifyUnit has N+1
- **Category**: Performance
- **Impact**: LOW-MEDIUM - Loops through users creating individual notifications
- **Effort**: S
- **Risk**: LOW
- **Confidence**: HIGH
- **Evidence**: 
  - `app/Services/NotificationService.php:31-37` - fetches users then loops with individual create()

## Recommended Execution Order

1. **Plan 001** - Add missing database indexes (highest impact, lowest risk)
2. **Plan 002** - Optimize HasOrganizationalScope for eager loading
3. **Plan 003** - Batch insert in NotificationService::notifyUnit
4. **Plan 004** - Optimize Unit descendantIds with materialized path or better caching (investigate first)

## Next Steps
Create detailed implementation plans for each finding in `plans/` directory.