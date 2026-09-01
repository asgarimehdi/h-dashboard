# 005 — Add API Rate Limiting Documentation & Headers

## Problem
API endpoints use `auth:sanctum` but the public documentation doesn't specify rate limits. The login endpoint has throttling (`throttle:login`), but other API endpoints don't have explicit rate limiting documented.

Additionally, API responses don't include standard rate-limit headers (`X-RateLimit-Limit`, `X-RateLimit-Remaining`).

## Proposal
1. Add `throttle:api` middleware to API route group in `routes/api.php`.
2. Configure rate limits in `AppServiceProvider::boot()` or `RouteServiceProvider`.
3. Add rate limit headers to API responses via middleware.
4. Update `AGENTS.md` API Reference section with rate limit details.
5. Add a test verifying rate limit headers are present.

## Files
- `routes/api.php`
- `app/Providers/AppServiceProvider.php`
- `AGENTS.md`
- New: `tests/Feature/ApiRateLimitTest.php`

## Risk: Medium
Could break existing API consumers if limits are too strict. Start with generous limits (60/min).
