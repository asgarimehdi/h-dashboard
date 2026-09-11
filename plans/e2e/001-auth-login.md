# Plan 001 — Authentication E2E Tests

**Priority:** P0 · **Written against:** `a737a5b` · **Status:** planned

## Why this matters

Authentication is the gate to the entire application. Every other test depends on a
working login. If login is broken, nothing else is testable. This plan covers the
login page, logout, password change, session persistence, and authorization-to-login
redirects — all from the browser, against the real Livewire stack (not just unit-tested
backend logic).

## Current-state excerpts

Login form (`resources/views/livewire/auth/login.blade.php`) uses Livewire single-file
component pattern:

- `n_code` input — text, `wire:model="n_code"`, required
- `password` input — password, `wire:model="password"`, required
- `remember` checkbox — `wire:model="remember"`
- Submit button `type="submit"` triggers `login()` Livewire action
- Error shown as Persian text `نام کاربری یا رمز عبور اشتباه است`

Credentials (from `database/seeders/UsersTableSeeder.php` + `.env`):
- Admin n_code: `4411015056`, password `12345678`

## Files in scope

- `tests/e2e/auth/login.spec.ts` (EXISTS — expand to full coverage)
- `tests/e2e/auth/logout.spec.ts` (NEW)
- `tests/e2e/auth/password-change.spec.ts` (NEW)
- `tests/e2e/shared/fixtures.ts` (EXTEND with helpers if needed)

## Files out of scope

- No source-code changes. No backend changes. Read-only exploration + test writing only.

## Conventions to follow

- Import from `../shared/fixtures` — use `login()`, `logout()`, `waitForToast()`,
  `expect` from there (NOT from `@playwright/test` directly).
- Persian assertions: match the exact UI strings (e.g. `نام کاربری یا رمز عبور اشتباه است`).
- Use `data-testid` is NOT available — locate by `#id`, role, or visible Persian text.
- Each `test()` must reset to a known state (navigate to `/login` first).

## Steps & verification gates

### 1. Login page renders (already passing — keep)

**Verify:** `npx playwright test tests/e2e/auth/login.spec.ts`

### 2. Full login test matrix

| # | Case | Expected |
|---|------|----------|
| 1 | valid creds | redirect `/dashboard`, title `h-dashboard` |
| 2 | empty n_code | validation: `لطفا کد ملی را وارد کنید` (or equivalent) |
| 3 | empty password | validation on password field |
| 4 | invalid n_code | `نام کاربری یا رمز عبور اشتباه است` |
| 5 | invalid password | same error |
| 6 | remember unchecked | session dies on browser close |

**Verify:** same command, all green.

### 3. Logout tests (`logout.spec.ts`)

- Login → click logout → assert redirect to `/login`, `auth` cookie cleared.
- Verify dashboard is NOT accessible after logout (navigate → redirect to login).

### 4. Password change tests (`password-change.spec.ts`)

Navigate `/users/changepassword`. Cases:
- valid current + matching new → success toast
- wrong current → error
- mismatched confirmation → error
- weak new password → error

**Verify:** `npx playwright test tests/e2e/auth/`

## Machine-checkable done criteria

```bash
cd /home/runner/h-dashboard
npx playwright test tests/e2e/auth/ --reporter=list
# EXPECT: all tests pass, 0 failures
```

## Test plan (what existing pattern to follow)

Follow `tests/e2e/auth/login.spec.ts` — it already establishes the correct `test.describe`
grouping, fixtures import, `page.fill`, `page.click`, `expect` usage.

## Maintenance note

If the login form field IDs change (`#n_code` / `#password`), update `fixtures.ts`'s
`login()` helper AND every spec that hardcodes these selectors. Keep the selector
definition in ONE place (fixtures) going forward.

## Escape hatches

- If the sign-out flow uses a different DOM structure than expected (no visible `خروج`
  text), STOP and inspect the actual header dropdown markup before guessing.
- If `waitForURL('**/dashboard')` races, use `waitForURL((url) => url.pathname === '/dashboard')` instead.