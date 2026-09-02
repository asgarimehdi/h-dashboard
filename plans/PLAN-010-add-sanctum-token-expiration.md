# Plan 010: Add Sanctum Token Expiration

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** High  
**Category:** Security  

## Problem

Sanctum API tokens never expire (`'expiration' => null`). This means:
1. Stolen tokens are valid forever
2. Compromised mobile devices retain access indefinitely
3. No automatic revocation of abandoned sessions
4. Violates security best practice for token-based auth

## Current State

**File:** `config/sanctum.php:47-58`

```php
/*
|--------------------------------------------------------------------------
| Expiration Minutes
|
| This value controls the number of minutes until an issued token will be
| considered expired. This will override any values set in the token's
| "expires_at" attribute, but first-party sessions are not affected.
|
*/

'expiration' => null,
```

First-party sessions (web UI) are explicitly NOT affected by this setting — they use Laravel's session lifetime, not Sanctum token expiration.

## Proposed Fix

Set a reasonable expiration. For a healthcare dashboard used on desktop + mobile:
- **24 hours (1440 minutes):** Good for daily mobile usage
- **7 days (10080 minutes):** More lenient, reduces re-login friction
- **Configurable via env:** Best practice

```php
'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 10080), // 7 days
```

Also add to `.env.example`:

```env
# Sanctum token expiration in minutes (null = never expires)
# 7 days = 10080, 24 hours = 1440
SANCTUM_TOKEN_EXPIRATION=10080
```

## Files to Modify

| File | Line | Change |
|------|------|--------|
| `config/sanctum.php` | 58 | `null` → `(int) env('SANCTUM_TOKEN_EXPIRATION', 10080)` |
| `.env.example` | — | Add `SANCTUM_TOKEN_EXPIRATION` documentation |

**Out of scope:** Token renewal logic in the Flutter app (separate concern), individual per-token expiration.

## Verification

```bash
# 1. Verify config loads correctly
php artisan tinker --execute 'echo config("sanctum.expiration");'
# Expected: 10080

# 2. Create a token and verify it has expiration
php artisan tinker --execute '
$user = App\Models\User::first();
$token = $user->createToken("test-token");
echo $token->accessToken->expires_at;
'
# Expected: a datetime 7 days in the future

# 3. Run Sanctum-related tests
composer test -- --filter="sanctum|token|api"
# Expected: all pass
```

## Test Plan

```php
it('sets expiration on new Sanctum tokens', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token');

    expect($token->accessToken->expires_at)->not->toBeNull()
        ->and($token->accessToken->expires_at->diffInMinutes(now()))->toBeGreaterThan(10000)
        ->and($token->accessToken->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(10080);
});

it('rejects expired token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('expired-token');
    
    // Manually expire the token
    $token->accessToken->update([
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->getJson('/api/user', [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ]);

    $response->assertStatus(401);
});
```

## STOP Conditions

- If the Flutter app doesn't handle 401 responses gracefully (needs token refresh logic)
- If there are long-running processes that use tokens for > 7 days
- If CI/CD pipelines use Sanctum tokens for automated API testing

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Flutter app doesn't refresh tokens | Users logged out | Coordinate with Flutter team; implement refresh endpoint |
| Automated tests use expired tokens | CI breaks | Use fresh tokens in test setup |
| 7 days too short for some users | Frustration | Make configurable via env; 7 days is a good default |

## Maintenance Notes

- Add a `POST /api/token/refresh` endpoint if the Flutter app needs seamless renewal
- Monitor token expiration rate in logs after deployment
- Consider adding a `last_used_at` column to track active vs abandoned tokens
