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

- Roles: `admin`, `expert`, `unit_manager`, `user`
- Permissions: functional (what you can do)
- Data scope: handled separately by `AccessService` (which units' data you see)

## Consequences
- **Pros**: Battle-tested, supports teams/guards, integrates with Sanctum, blade directives (`@can`, `@role`).
- **Cons**: Adds `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` tables.
- **Risk**: Permission bloat — keep permissions coarse; use data scope for fine-grained filtering.
