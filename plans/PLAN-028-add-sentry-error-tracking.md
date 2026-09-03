# Plan 028: Add Error Tracking (Sentry)

> **Branch:** tannaz · **Planned at:** cf3cf9c · **Date:** 2026-09-02

## Problem

Production has no error tracking beyond file logs. When errors occur in production (the self-hosted server at h-dashboard.ir), there's no way to get real-time alerts, stack traces, or context. File logs require SSH access to read and don't provide grouping/alerting.

### Current State

- `.env.example` has `LOG_CHANNEL=stack` and `LOG_LEVEL=debug`
- No Sentry, Bugsnail, or other error tracking service configured
- No `sentry/sentry-laravel` in `composer.json`
- `.github/workflows/deploy.yml` deploys to production (self-hosted, Apache)
- Local dev uses Laravel Debugbar (presumably — need to verify)

---

## Solution

Install `sentry/sentry-laravel`, add configuration, and ensure proper environment separation.

### Step 1: Install Package

```bash
composer require sentry/sentry-laravel
```

### Step 2: Add Sentry Configuration

**File:** `.env.example` (add at end):

```env
# Sentry Error Tracking (production only)
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.2
SENTRY_PROFILES_SAMPLE_RATE=0.1
```

**File:** `config/sentry.php` (auto-published by the package):

```php
<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'environment' => env('APP_ENV', 'production'),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),
    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),
    'send_default_pii' => false, // Don't send user PII to Sentry
];
```

### Step 3: Register Sentry Exception Handler

**File:** `app/Exceptions/Handler.php` (or `bootstrap/app.php` if using Laravel 13's new exception handling):

Add to `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    if (app()->environment('production') && env('SENTRY_LARAVEL_DSN')) {
        $exceptions->reportable(function (\Throwable $e) {
            \Sentry\captureException($e);
        });
    }
})
```

**Note:** In Laravel 13, the package may auto-register via the service provider. Check `vendor/sentry/sentry-laravel/src/SentryLaravelServiceProvider.php` for auto-discovery.

### Step 4: Environment Separation

| Environment | SENTRY_LARAVEL_DSN | Behavior |
|-------------|-------------------|----------|
| local | empty/null | Errors shown via Debugbar or file log only |
| staging | DSN set | Sentry captures errors, sends to staging project |
| production | DSN set | Sentry captures errors, sends alerts |

### Step 5: Set Production DSN

After deploying, set the DSN on the production server:
```bash
echo 'SENTRY_LARAVEL_DSN=https://...@sentry.io/...' >> .env
php artisan config:cache
```

### Step 6: Verify Debugbar Is NOT in Production

**File:** `config/app.php` (or `composer.json`):

Debugbar should already be disabled in production via the package's auto-detection. Verify:

```php
// In config/debugbar.php (if exists):
'enabled' => env('APP_DEBUG', false),
```

Or check that Debugbar is in `require-dev` only (not `require`):
```bash
grep 'barryvdh/laravel-debugbar' composer.json
```
Expected: in `require-dev` section.

---

## Verification

1. **Install and verify:**
   ```bash
   composer require sentry/sentry-laravel
   php artisan config:cache
   ```

2. **Test error capture (local, with DSN):**
   ```bash
   # Set a test DSN in .env
   SENTRY_LARAVEL_DSN=https://...@sentry.io/...
   php artisan tinker --execute 'throw new \Exception("Sentry test error");'
   ```
   Expected: Error appears in Sentry dashboard within 30 seconds.

3. **Verify no PII in Sentry:**
   - Check `send_default_pii` is `false`
   - Verify user email/n_code are not in Sentry events

4. **Verify Debugbar doesn't conflict:**
   ```bash
   APP_ENV=production php artisan route:list
   ```
   Expected: No Debugbar output.

---

## STOP Conditions

- If `sentry/sentry-laravel` requires a different Laravel version, check compatibility.
- If the production server blocks outbound HTTPS (needed for Sentry), configure a proxy or use Sentry Relay.
- If Sentry's auto-discovery conflicts with Debugbar, disable one in the appropriate environment.

---

## Out of Scope

- Setting up a Sentry account/project (team must do this manually).
- Adding breadcrumbs or performance monitoring (beyond the basic DSN config).
- Adding Sentry to CI/test environments.
- Monitoring queue workers with Sentry.
- Setting up Sentry alerts/notifications (email, Slack).

---

## Test Plan

| # | Test | Expected |
|---|------|----------|
| 1 | `composer require sentry/sentry-laravel` | Installs without conflicts |
| 2 | `php artisan config:cache` | No errors |
| 3 | Throw exception with DSN set | Error appears in Sentry |
| 4 | Throw exception without DSN | No errors, falls back to log |
| 5 | `APP_ENV=production php artisan route:list` | No Debugbar output |
| 6 | `grep 'send_default_pii' config/sentry.php` | `false` |

---

## Maintenance Notes

- **DSN management:** Never commit the DSN to git. Store in `.env` on production server.
- **Sampling rate:** `SENTRY_TRACES_SAMPLE_RATE=0.2` captures 20% of transactions for performance monitoring. Increase to 1.0 for debugging, decrease for high-traffic.
- **Release tracking:** Configure `SENTRY_RELEASE` in `.env` to track which deploy introduced an error:
  ```env
  SENTRY_RELEASE=abc1234  # git commit SHA
  ```
- **Alerting:** Set up Sentry alerts for new errors and performance regressions via the Sentry dashboard.
