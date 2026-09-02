# Plan 011: Enable Session Encryption and Secure Cookie

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3rf9c  
**Priority:** High  
**Category:** Security  

## Problem

Sessions are stored unencrypted:
1. `SESSION_ENCRYPT=false` — session payload is readable in Redis/DB (anyone with Redis access can read session data including auth state)
2. `SESSION_SECURE_COOKIE` is not set — session cookies may be sent over HTTP (man-in-the-middle can steal them)

## Current State

### config/session.php

```php
// Line 50
'encrypt' => env('SESSION_ENCRYPT', false),      // ← NOT ENCRYPTED

// Line 172
'secure' => env('SESSION_SECURE_COOKIE'),          // ← NULL (not set)
```

### .env.example

```env
SESSION_DRIVER=file
SESSION_LIFETIME=120
# No SESSION_ENCRYPT or SESSION_SECURE_COOKIE defined
```

## Proposed Fix

### 1. config/session.php

```php
// Enable encryption
'encrypt' => env('SESSION_ENCRYPT', true),

// Require HTTPS for cookie (set to null for local dev flexibility)
'secure' => env('SESSION_SECURE_COOKIE'),
```

### 2. .env.example — add documentation

```env
SESSION_DRIVER=file
SESSION_LIFETIME=120

# Encrypt session data (recommended for production)
SESSION_ENCRYPT=true

# Only send session cookie over HTTPS (set to true in production)
# Leave null to auto-detect (encrypted in prod, plain in local)
SESSION_SECURE_COOKIE=null

# Same-site cookie policy (lax, strict, none)
SESSION_SAME_SITE=lax
```

### 3. .env for production

```
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
```

**Note:** `SESSION_SECURE_COOKIE=null` in `.env.example` lets Laravel auto-detect (HTTPS in prod → secure; HTTP in dev → not secure). Production `.env` should explicitly set `true`.

## Files to Modify

| File | Change |
|------|--------|
| `config/session.php` | Line 50: default `false` → `true` |
| `.env.example` | Add `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE` with documentation |

**Out of scope:** The `.env` file itself (production-specific), Redis configuration, session table encryption.

## Verification

```bash
# 1. Verify encryption is enabled
php artisan tinker --execute 'echo config("session.encrypt") ? "encrypted" : "plain";'
# Expected: encrypted

# 2. Check session cookie in browser DevTools
# - Should see: Secure flag (if HTTPS)
# - Payload in Redis should not contain plaintext user data

# 3. Run session-related tests
composer test -- --filter="session|auth|login"
# Expected: all pass

# 4. Verify existing sessions aren't broken
# After enabling encryption, old unencrypted sessions will be invalidated
# Users will need to re-login once — this is expected
```

## Test Plan

```php
it('session encrypt option defaults to true', function () {
    expect(config('session.encrypt'))->toBeTrue();
});

it('session data is encrypted in storage', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    
    $this->get('/dashboard');
    
    $sessionId = session()->getId();
    $rawPayload = Cache::store('database')->get("sessions:{$sessionId}");
    
    // Encrypted payload should not contain plaintext user data
    expect($rawPayload)->not->toContain($user->name)
        ->and($rawPayload)->not->toContain($user->email);
});
```

## STOP Conditions

- If the `APP_KEY` is not set (encryption requires it)
- If the session driver doesn't support encryption (all Laravel-supported drivers do)
- If there are active users who would be forcibly logged out (communicate before deploy)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Old sessions invalidated on deploy | All users logged out | Announce maintenance window; one-time inconvenience |
| SESSION_SECURE_COOKIE blocks HTTP dev | Dev workflow broken | Use `null` default (auto-detect) |
| Performance impact of encryption | Negligible | AES encryption is fast; only on session read/write |

## Maintenance Notes

- After deploying, monitor for users reporting unexpected logouts
- The `SESSION_ENCRYPT=true` default means new installations are secure by default
- Consider adding `SESSION_SAME_SITE=strict` for maximum CSRF protection
