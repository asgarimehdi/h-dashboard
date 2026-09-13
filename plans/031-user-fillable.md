# 031 — Remove `password` from User::$fillable

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | MEDIUM (Security best practice) |
| **Effort** | S |
| **Risk** | Medium — affects all User mass-assignment paths |
| **Base commit** | `a106d38` |
| **Files** | `app/Models/User.php`, `resources/views/livewire/auth/register.blade.php`, `resources/views/livewire/users/index.blade.php`, test files |

## Problem

`app/Models/User.php:24-28` includes `'password'` in `$fillable`:

```php
protected $fillable = [
    'n_code',
    'password',
    'settings',
];
```

Having `password` in `$fillable` means any mass-assignment (`User::create([...])`, `User::fill([...])`, `update([...])`) can set the password. While Laravel's `'hashed'` cast (line 105) ensures the value is hashed, the security concern is:

1. Accidental exposure: if any route or handler ever passes unsanitized input to `User::update()` with a `password` field, it could be overwritten.
2. Mass-assignment is the wrong pattern for passwords — they should be set via explicit attribute assignment or a dedicated method.

### Evidence — Current `User::create()` call sites

All current call sites properly hash the password before passing it:

- `register.blade.php:56`: `User::create([... 'password' => Hash::make(...)])`
- `users/index.blade.php:150`: `User::create([... 'password' => Hash::make(...)])`
- `tests/Feature/*`: All use `User::create(['n_code' => ..., 'password' => Hash::make('password')])` or `bcrypt()`

So removing `password` from `$fillable` will break these calls. The fix requires updating each call site to set `password` explicitly.

### Evidence (file:line)

- **`app/Models/User.php:24-28`** — `password` in `$fillable`
- **`app/Models/User.php:105`** — `'password' => 'hashed'` cast (auto-hashes on set)
- **`resources/views/livewire/auth/register.blade.php:56`** — `User::create(... 'password' => Hash::make(...))`
- **`resources/views/livewire/users/index.blade.php:150`** — `User::create(... 'password' => Hash::make(...))`

## Decision

Remove `'password'` from `$fillable` and update all call sites to use `User::create()` without password, then set `$user->password = Hash::make(...)` explicitly. Since the model has `'password' => 'hashed'` cast, the explicit set will still auto-hash.

**Alternative:** Use `User::forceCreate()` at the call sites — but this is less explicit and hides intent. The explicit approach is cleaner.

## Commands

```bash
cd /home/runner/h-dashboard
# Find all User::create and User::fill calls
grep -rn 'User::create\|User::fill' --include='*.php' app/ resources/views/ tests/
# Verify password is in fillable
grep -n 'password' app/Models/User.php
```

## Steps

### Phase 1 — Update `app/Models/User.php`

Remove `'password'` from `$fillable`:

```php
protected $fillable = [
    'n_code',
    'settings',
];
```

Add a helper method (optional but recommended):

```php
/**
 * Set the user's password (auto-hashed via 'hashed' cast).
 */
public function setPasswordAttribute(string $value): void
{
    $this->attributes['password'] = Hash::make($value);
}
```

Actually, the `'hashed'` cast already handles this, so no custom mutator is needed. The explicit assignment `$user->password = Hash::make($value)` will work because the cast hashes the value.

**Wait:** With the `'hashed'` cast, if we do `$user->password = Hash::make('test')`, it will double-hash (hash the already-hashed string). The correct approach with the `'hashed'` cast is:

```php
$user->password = 'test'; // The 'hashed' cast hashes it automatically
```

So call sites should use `$user->password = 'plaintext'` instead of `$user->password = Hash::make('plaintext')`.

### Phase 2 — Update `register.blade.php`

```php
$user = User::create([
    'n_code' => $nCode,
]);
$user->password = $password; // hashed cast handles it
$user->save();
```

Or alternatively, keep using `Hash::make` if we remove the `hashed` cast from `password`. But the `hashed` cast is the Laravel 13 standard approach, so we should keep it and use raw passwords.

### Phase 3 — Update `users/index.blade.php`

Same pattern: create user without password, then set it.

### Phase 4 — Update test files

All test files that do `User::create(['n_code' => ..., 'password' => Hash::make('password')])` need updating:

```php
$user = User::create(['n_code' => $nCode]);
$user->password = 'password';
$user->save();
```

**Files to update** (from search results):
- `tests/Feature/HardwareAuditObserverRequestTest.php:60`
- `tests/Feature/UnitTicketCapabilityTest.php:34,58`
- `tests/Feature/TicketCommentsEdgeCasesTest.php:47,73`
- `tests/Feature/MapsUnitLivewireTest.php:51`
- `tests/Feature/SettingsDashboardRefreshTest.php:38`
- `tests/Feature/MultiLatestValueApiTest.php:100`
- `tests/Feature/ApiRateLimitTest.php:48`
- `tests/Feature/HardwareScopeTest.php:66`
- `tests/Feature/TicketCommentsLivewireTest.php:46`
- `tests/Feature/HardwareIndexLivewireTest.php:49`
- `tests/Feature/HardwareExportNormalizationTest.php:47`
- `tests/Feature/HrApiTest.php:48`
- `tests/Feature/LogoutTest.php:40`
- `tests/Feature/TicketApiTest.php:50,195,239`

### Phase 5 — Run tests

```bash
composer test
vendor/bin/pint --dirty --format agent
```

## Test plan

- All existing tests pass after updating User::create() calls.
- Verify that `User::create(['n_code' => 'test', 'password' => 'secret'])` now ignores the password field (it won't be set via mass assignment).
- Verify that explicit `$user->password = 'secret'; $user->save()` works correctly with the `hashed` cast.

## Done criteria

- [ ] `'password'` is NOT in `User::$fillable`
- [ ] All `User::create()` calls updated to set password explicitly
- [ ] All tests pass
- [ ] `vendor/bin/pint --dirty` clean
- [ ] `password` field is still properly hashed on save (verify via `Hash::check()`)

## STOP conditions

- If any call site uses `User::forceFill(['password' => ...])` that isn't in the search results, STOP and update it too.
- If the `hashed` cast double-hashes when combined with `Hash::make()`, STOP — the fix must use raw password assignment, not `Hash::make()`.
- If the Flutter API login depends on password being set via mass assignment somewhere, STOP and check.
