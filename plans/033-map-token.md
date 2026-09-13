# 033 — Fix Map Dashboard Token Churn

| Field | Value |
|---|---|
| **Status** | Ready to execute |
| **Priority** | MEDIUM (Performance + security hygiene) |
| **Effort** | S |
| **Risk** | Low — token lifecycle change |
| **Base commit** | `a106d38` |
| **Files** | `resources/views/livewire/map/map-dashboard.blade.php` |

## Problem

`resources/views/livewire/map/map-dashboard.blade.php:38-45` deletes and recreates a Sanctum API token on **every mount** of the Livewire component:

```php
public function mount()
{
    $user = auth()->user();
    // Always create a new token — Sanctum stores only the hash,
    // so plainTextToken is null on tokens loaded from DB.
    // Delete old map-dashboard tokens first to avoid accumulation.
    $user->tokens()->where('name', 'map-dashboard')->delete();
    $this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
```

This is wasteful and has performance implications:
1. **Every page visit** triggers a DELETE + INSERT on the `personal_access_tokens` table.
2. With Livewire reactive updates or `wire:navigate`, mounts happen frequently.
3. The comment explains the reason: Sanctum stores only the hash, so you can't read back the plain-text token. But the solution doesn't need to recreate on every mount.

### Evidence (file:line)

- **`resources/views/livewire/map/map-dashboard.blade.php:40-45`** — delete + create pattern.
- **Line 41-42 comment**: `// Always create a new token — Sanctum stores only the hash, // so plainTextToken is null on tokens loaded from DB.`
- **Line 43**: `$user->tokens()->where('name', 'map-dashboard')->delete();`
- **Line 44**: `$this->mapToken = $user->createToken('map-dashboard')->plainTextToken;`

The token is used in:
- **Line 46**: `$this->mapTileTemplate = ...` (not token-related)
- **Line 116**: `Http::withToken($this->mapToken)->get(route('api.gis.stats'), ...)` (Livewire server-side)
- **Line 263**: `apiToken: '{{ $mapToken }}'` (JavaScript client-side for GIS API calls)

## Decision

**Option 1 (Recommended): Create token only once, store plaintext in session**

On first mount, create the token and store the plaintext in the Laravel session. On subsequent mounts, check session first. Only recreate if session is empty.

```php
public function mount()
{
    $user = auth()->user();
    $sessionKey = 'map_dashboard_token_' . $user->id;
    
    $this->mapToken = session($sessionKey);
    
    if (!$this->mapToken) {
        // Clean up any orphaned tokens
        $user->tokens()->where('name', 'map-dashboard')->delete();
        $this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
        session([$sessionKey => $this->mapToken]);
    }
    
    $this->mapTileTemplate = config('map.tile_url_template', '...');
    $this->loadStats();
}
```

**Why session is safe:** The token is already session-scoped — it's created per-user and used only while the user is authenticated. Session storage is appropriate for this use case.

**Why not storage disk or cache:** Sessions are already used for auth state. Adding another persistence layer is unnecessary complexity for a token that's only needed while the user is logged in.

**Cleanup on logout:** Add a listener on logout to revoke map-dashboard tokens (or rely on the existing token cleanup on login).

## Commands

```bash
cd /home/runner/h-dashboard
XDEBUG_MODE=off php artisan test tests/Feature/MapDashboardTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/MapDashboardLivewireTest.php -v
```

## Steps

### Phase 1 — Update mount() to use session-based token

Replace lines 38-48:

```php
public function mount()
{
    $user = auth()->user();
    $sessionKey = 'map_token_' . $user->id;

    // Reuse token from session to avoid DELETE+INSERT on every mount
    $this->mapToken = session($sessionKey);

    if (! $this->mapToken) {
        // First visit this session — create a fresh token
        // Clean up any orphaned tokens from previous sessions
        $user->tokens()->where('name', 'map-dashboard')->delete();
        $this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
        session([$sessionKey => $this->mapToken]);
    }

    $this->mapTileTemplate = config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
    $this->loadStats();
}
```

### Phase 2 — Add token cleanup on logout

In the logout handler (check `routes/web.php` or `AuthController`), revoke map-dashboard tokens:

```php
// After logout, clean up map tokens
auth()->user()?->tokens()->where('name', 'map-dashboard')->delete();
```

Or add it to the `LogoutTest` to verify tokens are cleaned.

### Phase 3 — Verify token reuse

1. Load the map dashboard page — token should be created (INSERT in `personal_access_tokens`).
2. Navigate away and back — token should be reused (no DELETE/INSERT).
3. Logout and login again — new token should be created.

### Phase 4 — Run tests

```bash
XDEBUG_MODE=off php artisan test tests/Feature/MapDashboardTest.php -v
XDEBUG_MODE=off php artisan test tests/Feature/MapDashboardLivewireTest.php -v
composer test
```

## Test plan

- Existing `MapDashboardTest` and `MapDashboardLivewireTest` confirm functionality.
- Add a test that verifies token count doesn't grow on repeated mounts:

```php
test('map dashboard reuses token across mounts', function () {
    $user = $this->createUserWithPermission('map');
    $this->actingAs($user);

    Livewire::test(MapDashboard::class);
    $tokenCount = $user->tokens()->where('name', 'map-dashboard')->count();
    expect($tokenCount)->toBe(1);

    // Mount again — should reuse
    Livewire::test(MapDashboard::class);
    expect($user->tokens()->where('name', 'map-dashboard')->count())->toBe(1);
});
```

## Done criteria

- [ ] Token is created only once per session, not on every mount
- [ ] `personal_access_tokens` row count doesn't grow on repeated visits
- [ ] GIS API calls still work with the reused token
- [ ] Token is cleaned up on logout
- [ ] Map dashboard tests pass
- [ ] `vendor/bin/pint --dirty` clean

## STOP conditions

- If session storage is unreliable (e.g., array driver in tests doesn't persist), use a database-backed session or store in `Cache::remember` instead.
- If the token expires before the session ends (Sanctum TTL), STOP and adjust TTL or recreate on 401 response.
- If the Flutter mobile app shares this code path, STOP — the mobile app should have its own persistent token.
