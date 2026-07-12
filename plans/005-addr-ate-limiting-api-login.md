# Plan 005: Add rate limiting to API login endpoint
Drift: git diff --stat 25c206a4..HEAD -- routes/api.Php
Commit: 25c206a4 | Priority: P2 | Effort: S | Risk: LOW | Category: security

## Why
POST /api/login authenticates with n_code (10-digit). Brute-force is unthrottled. Laravel throttle:5,1 (5 attempts/minute) adds friction without breaking legitimate use.

## Current state
routes/api.Php:14 — login route has no middleware.
Route::post('/login', function (Request $request) { ... });

## Steps
### 1. Add throttle middleware
Wrap the login route with ->middleware('throttle:5,1'):
Route::post('/login', function (Request $request) {
    ...
})->middleware('throttle:5,1');
Verify: grep -n "throttle" routes/api.Php (shows ->middleware('throttle:5,1'))

### 2. Test rate limiting
Send 6 rapid POST requests to /api/login with invalid credentials. 6th returns HTTP 429.
Laravel uses file cache driver for throttle in dev — this works fine without Redis.

## Done
- [ ] grep "throttle" routes/api.Php shows ->middleware('throttle:5,1')
- [ ] php artisan route:list --path=api/login shows throttle:5,1
- [ ] vendor/../pint --dirty exits 0

## STOP
Stop if throttle middleware not registered. Verify: grep -n "throttle" bootstrap/app.Php. If absent, report and stop.