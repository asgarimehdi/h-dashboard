# Plan 009: Fix Logout CSRF — Change GET to POST

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** High  
**Category:** Security  

## Problem

The logout route uses `GET`, which means:
1. A malicious page can force logout via `<img src="/logout">` or `<a href="/logout">` (CSRF)
2. Browser prefetching/extensions may accidentally trigger logout
3. Violates HTTP semantics — state-changing operations should use POST (or DELETE)

## Current State

### Route

**File:** `routes/web.php:19-34`

```php
Route::get('/logout', function () {
    $userId = Auth::id();
    $userName = Auth::user()?->name ?? 'نامشخص';

    if ($userId) {
        ActivityLogService::logout('خروج از سیستم - کاربر: '.$userName);
        Session::forget("user_{$userId}_display_name");
    }

    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
});
```

### Logout button in layout

**File:** `resources/views/components/layouts/app.blade.php:171-172`

```blade
<x-button icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-right="logoff"
    no-wire-navigate link="/logout" />
```

This is a MaryUI `<x-button>` that renders as an `<a>` tag — a GET request.

## Proposed Fix

### 1. Change route to POST

```php
Route::post('/logout', function () {
    $userId = Auth::id();
    $userName = Auth::user()?->name ?? 'نامشخص';

    if ($userId) {
        ActivityLogService::logout('خروج از سیستم - کاربر: '.$userName);
        Session::forget("user_{$userId}_display_name");
    }

    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('logout');
```

### 2. Update logout button to use a POST form

Replace the `<x-button>` with a form-based POST:

```blade
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <x-button type="submit" icon="o-power" class="btn-circle btn-ghost btn-xs"
        tooltip-right="logoff" no-wire-navigate />
</form>
```

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `routes/web.php` | 19 | `Route::get('/logout', ...)` → `Route::post('/logout', ...)` |
| `resources/views/components/layouts/app.blade.php` | 171-172 | Wrap button in POST form with `@csrf` |

**Out of scope:** Any other logout links (search confirms only one location).

## Verification

```bash
# 1. GET /logout should return 405 Method Not Allowed
curl -v http://localhost:8000/logout
# Expected: HTTP/1.1 405 Method Not Allowed

# 2. POST /logout with CSRF should work
# (manual browser test — click logout button, should work)

# 3. Search for any remaining GET /logout references
grep -r "/logout" resources/views/
# Expected: no `<a href="/logout">` or `link="/logout"` (only form action)

# 4. Run full test suite
composer test
# Expected: 928+ pass, 0 fail
```

## Test Plan

```php
it('rejects GET request to logout', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/logout');
    $response->assertStatus(405);
});

it('logs out via POST', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post('/logout');
    $response->assertRedirect('/');
    $this->assertGuest();
});
```

## STOP Conditions

- If any Blade templates have `<a href="/logout">` that need updating
- If there are JavaScript fetch/XHR calls to `/logout` using GET
- If the MaryUI `<x-button>` component doesn't support `type="submit"` in a form context

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Form breaks MaryUI styling | UI regression | Test button renders correctly in form |
| Missing @csrf causes 419 | Logout broken | Standard Laravel CSRF token |
| Other logout references exist | CSRF still possible on some paths | Grep to confirm only one location |

## Maintenance Notes

- Add `->name('logout')` for named route reference
- Consider moving to a proper `LogoutController` for testability
- The same pattern (GET→POST) should be reviewed for any other state-changing routes
