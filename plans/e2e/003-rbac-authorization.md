# Plan 003 — RBAC & Authorization E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** planned · **Depends:** 001

## Why

Spatie roles/permissions gate every module. 4 roles (admin, unit_manager, expert,
user), 17 permissions. Authorization must be verified from the browser — a page that
loads its UI but leaks data via Livewire is a real vulnerability, invisible to backend
tests.

## Roles & expected access (from AGENTS.md + `/permissions` page)

| Route group | admin | unit_manager | expert | user |
|---|---|---|---|---|
| /users | ✓ | ✗ | ✗ | ✗ |
| /roles, /permissions | ✓ | ✗ | ✗ | ✗ |
| /hardware | ✓* | ✓ (own unit) | limited | ✗ |
| /reports/* | ✓ | ✓ | partial | ✗ |
| /settings | ✓ | ✓ | ✓ | ✓ |
| /profile | ✓ | ✓ | ✓ | ✓ |

*`manage_hardware` permission required.

## Scenarios (tests/e2e/rbac/)

| # | Case | Expected |
|---|------|----------|
| 1 | admin sees all sidebar items | full menu |
| 2 | non-admin sees filtered menu | forbidden items hidden |
| 3 | non-admin direct-navigates `/users` | 403 or redirect, no data |
| 4 | non-admin direct-navigates `/roles` | 403/redirect |
| 5 | `/hardware` without `manage_hardware` | blocked |
| 6 | org-scope: user sees only own unit data | list filtered |
| 7 | logout then access protected route | redirect `/login` |

## IMPORTANT safety rule

Do NOT attempt cross-unit data access beyond logging in as each test role and asserting
the UI hides/forbids what it should. Never script privilege escalation against prod.

## Files

- `tests/e2e/rbac/roles.spec.ts` (login as each of the 4 roles)

## Done criteria

```bash
npx playwright test tests/e2e/rbac/ --reporter=list  # all pass
```

## Escape hatch

If only one test user exists in the DB, STOP — do not fabricate others. Report that
RBAC testing needs additional seeded role accounts and proceed to non-auth plans.