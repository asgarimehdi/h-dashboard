# Plan 004 — Users CRUD E2E Tests

**Priority:** P0 · **Base:** `a737a5b` · **Status:** ✅ done

## Resolution

The standalone routes `/users/create` and `/users/{id}/edit` pointed at
`users.create` / `users.edit` views that were never implemented (HTTP 500).
The create/edit flows already exist as an inline modal in `users.index`
(`openFormForCreate` / `edit`), so the dead routes were **removed** (now 404)
instead of building redundant pages. `crud.spec.ts` covers the real flows.

## Cases (tests/e2e/users/)

### list.spec.ts
| # | Case | Expected |
|---|------|----------|
| 1 | users list loads | table, 20 rows/page, columns # | نام | کد ملی | واحد اصلی | نقش‌ها | وضعیت |
| 2 | search by name | filters rows (Livewire `.live.debounce`) |
| 3 | filter active/inactive | status select filters |
| 4 | search by n_code | filters rows |
| 5 | page size 10/20/50/100 | row count changes |
| 6 | paginate next/prev | page changes |
| 7 | expand row shows roles | expansion opens |

### crud.spec.ts (inline modal on /users)
| # | Case |
|---|------|
| 1 | removed standalone routes return 404 (not 500) |
| 2 | open create modal renders full form |
| 3 | create with duplicate n_code → validation (no mutation) |
| 4 | edit opens prefilled → close without saving (no mutation) |
| 5 | delete → dismiss confirm → user kept |
| 6 | delete → accept → soft-deleted → restore → active again (net-zero) |

## Files

- `tests/e2e/users/list.spec.ts`
- `tests/e2e/users/crud.spec.ts`

## Done criteria

```bash
./node_modules/.bin/playwright test tests/e2e/users/ --reporter=list  # 14 pass
```
