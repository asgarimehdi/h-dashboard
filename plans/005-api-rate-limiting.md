# 005 — API Rate Limiting Documentation & Headers

## Problem
> **⚠️ Update (2026-09-02 audit):** Most of this plan is **already implemented**:
> - `routes/api.php` login endpoint has `throttle:5,1` (matches AGENTS.md "throttled 5/min").
> - The whole `auth:sanctum` group is wrapped in `Route::middleware(['auth:sanctum', 'throttle:60,1'])` — i.e. the "generous 60/min" limit proposed below already exists.
> - Laravel's `throttle` middleware already emits standard headers (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`) on every throttled response — no custom middleware needed.

## Proposal (residual work)
1. ~~Add `throttle:api`~~ — **drop this**; do not introduce a named `api` limiter on top of the existing `throttle:60,1`. If a named limiter is ever wanted, configure it in `AppServiceProvider::boot()` via `RateLimiter::for('api', …)` and replace the inline `throttle:60,1` in the same PR.
2. Verify headers are present on API responses (quick manual cURL check).
3. Add `ApiRateLimitTest.php`:
   - assert `X-RateLimit-Limit` = 60 and `X-RateLimit-Remaining` present on an authenticated API request;
   - assert login endpoint returns 429 after 6 attempts within a minute (`$this->withoutExceptionHandling()` not needed; use `ThrottleRequestsException` / status 429).
   - Use the hermetic test env (array cache drives RateLimiter — no Redis needed).
4. Update `AGENTS.md` API Reference section: add a "Rate Limits" subsection documenting 60/min for `/api/*` (auth group) and 5/min for `/api/login`, plus header behavior for the Flutter app team.

## Files
- `AGENTS.md`
- New: `tests/Feature/ApiRateLimitTest.php`
- ~~`routes/api.php`~~ / ~~`app/Providers/AppServiceProvider.php`~~ — no changes required

## Risk: Low (downgraded from Medium — no middleware change, additive test + docs only)
