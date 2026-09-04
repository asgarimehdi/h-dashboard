# Plan 009: Add Livewire coverage for auth.register

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `HEAD`, 2026-09-04
- **Issue**: https://github.com/asgarimehdi/h-dashboard/issues/568

## Why this matters

The `auth.register` Livewire component currently has **no dedicated Livewire test
coverage**. Adding Pest tests via `Livewire::test()` ensures that validation rules,
auth gates, user creation, session regeneration, and error branches are verified —
preventing regressions and documenting expected behavior for future contributors.

## Current state

- **Component**: `auth.register` — single-file anonymous Livewire 4 class under
  `resources/views/livewire/auth/register.blade.php` (see AGENTS.md: no
  `app/Livewire/*.php` files).
- **Route**: COMMENTED OUT in `routes/web.php:18`. The component is tested directly
  via `Livewire::test('auth.register')` — no HTTP route needed.
- **Conventions**: Pest tests under `tests/Feature/`, run via `composer test`.
  Model after `tests/Feature/Auth/LoginLivewireTest.php`.
- **Auth**: session-based for Livewire (`actingAs`); Sanctum Bearer tokens NOT
  accepted for Livewire pages.
- **Known quirk**: The component uses `request()->session()->regenerate()` (not
  the `Session` facade). Livewire tests skip the `StartSession` middleware via
  `withoutMiddleware()`, so the test kernel must be wrapped to attach the session
  store to every request. See setUp in the test file.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|-------------------|
| Start DB | `docker compose -f docker-compose-pgsql-.yml up -d` | PostGIS healthy on :5432 |
| Config clear | `php artisan config:clear && php artisan route:clear` | No stale caches |
| Run focused | `XDEBUG_MODE=off php artisan test tests/Feature/AuthRegisterLivewireTest.php` | 7 passed |
| Format | `vendor/bin/pint --dirty --format agent` | Clean |

## Scope

**In scope** (the ONLY file you may create/modify):
- `tests/Feature/AuthRegisterLivewireTest.php` (CREATE)
- `plans/009-auth-register.md` (CREATE)

**Out of scope** (do NOT touch, even though they look related):
- Any production source file (`app/`, `resources/views/`, `routes/`)
- Any existing test file
- Config, migrations, seeders, `composer.json`, `package.json`

## Steps

### Step 1: Verify current state on disk

```bash
ls resources/views/livewire/auth/register.blade.php
grep -n "auth.register" routes/web.php | head -5
grep -rn 'Livewire::test("auth.register")' tests/ | head -5
```

**Verify**: component file exists; route is commented out; no existing dedicated
test file for this component.

### Step 2: Write the test file

Create `tests/Feature/AuthRegisterLivewireTest.php` following the exemplar
pattern (adapted from `tests/Feature/Auth/LoginLivewireTest.php`):

- `use RefreshDatabase;`
- `setUp()`: seed lookup rows (`tahsils`, `estekhdams`, `semats`, `radifs`)
  with explicit `id=1`; resync Postgres sequences; wrap HTTP kernel to attach
  session store (see known quirk above).
- helper `createPerson(string $nCode)`: create `Unit`, `Person` with the given
  `n_code` and all required FKs.
- 7 test methods:

| Method | What it tests |
|--------|---------------|
| `test_guest_renders` | Guest can mount component, sees form fields |
| `test_authed_redirects` | Authenticated user → redirect to `/` |
| `test_registers_valid` | Valid n_code + matching passwords → User created, logged in, redirected `/` |
| `test_session_regenerated` | After register, session token changes |
| `test_validation_errors` | Empty/short/mismatched → validation errors |
| `test_person_missing` | n_code not in persons → error on n_code |
| `test_user_duplicate` | n_code already in users → error on n_code |

### Step 3: Format and run

```bash
XDEBUG_MODE=off php artisan test tests/Feature/AuthRegisterLivewireTest.php
vendor/bin/pint --dirty --format agent
```

**Verify**: 7 tests pass; pint clean.

### Step 4: Commit and push

```bash
git add tests/Feature/AuthRegisterLivewireTest.php plans/009-auth-register.md
git commit -m 'test(009): add AuthRegisterLivewireTest for auth.register component'
git push origin bahar
```

## Test plan

New tests in `tests/Feature/AuthRegisterLivewireTest.php`:

- `test_guest_renders`
- `test_authed_redirects`
- `test_registers_valid`
- `test_session_regenerated`
- `test_validation_errors`
- `test_person_missing`
- `test_user_duplicate`

## Done criteria

Machine-checkable. ALL must hold:

- [ ] Focused run exits 0
- [ ] Every method in "Test plan" exists and passes
- [ ] `vendor/bin/pint --dirty` reports no changes
- [ ] `git status -- tests/` shows ONLY the new test file
- [ ] Committed and pushed to `bahar`

## STOP conditions

Stop and report back (do not improvise) if:

- The component file does not exist at the expected path (codebase drifted).
- A focused test run fails due to environment (PostGIS down, config cache,
  Redis NOAUTH) — fix env first via AGENTS.md checklist, do not relax asserts.
- The component needs a permission or middleware not listed in "Current
  state" — report, do not guess.
- A step's verification fails twice after a reasonable fix attempt.
- The work appears to require touching an out-of-scope file.
