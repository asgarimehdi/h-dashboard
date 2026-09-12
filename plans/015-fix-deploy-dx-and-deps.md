# Plan 015: Fix deploy/DX issues + deps

| Field      | Value                              |
|------------|------------------------------------|
| Category   | dx                                 |
| Effort     | S                                  |
| Risk       | HIGH                               |
| Priority   | P1                                 |
| Depends on | none                               |
| Base SHA   | 5f9c24e                            |
| Branch     | celin                              |

---
## ⚠️ TL;DR فارسی

**مشکل:** /health نیست. Apache2 به جای Nginx. composer.lock ایگنور. boost در prod.

**ریسک:** 🟡 متوسط

---

## 1. Problem

The deploy workflow has a broken health check (curl hits `/health` which
doesn't exist), references the wrong web server (apache2 vs nginx), and
composer.lock is excluded by `.gitignore` so CI can't use lockfile-based
install. PHPStan baseline is 267KB and growing with no size guard.

## 2. What exists today

| Issue | Location | Detail |
|-------|----------|--------|
| Missing `/health` route | `routes/web.php` | `deploy.yml:136` curls `GET /health` but no such route exists. The curl has `continue-on-error: true` so it silently passes. |
| Wrong web server | `.github/workflows/deploy.yml:133` | Runs `sudo systemctl reload apache2`. The project docs and deployment context use nginx. |
| composer.lock excluded | `.gitignore:35` | `*.lock` excludes `composer.lock`. CI (`deploy.yml:48`) uses `hashFiles('composer.lock')` for cache key — this fails silently (empty hash). |
| laravel/boost in require | `composer.json:13` | `laravel/boost` is a dev-only MCP server tool. Should be in `require-dev`. |
| morilog/jalali exact pin | `composer.json:19` | `"3.5"` exact pin prevents patch updates. Should be `"^3.5"`. |
| PHPStan baseline unchecked | `phpstan-baseline.neon` (267KB) | No CI guard — baseline can grow unbounded as new errors are suppressed instead of fixed. |

## 3. Implementation steps

### Step 1 — Add GET /health route

Add to `routes/web.php` (outside any middleware group, at the top):

```php
Route::get('/health', fn () => response()->json(['status' => 'ok'], 200));
```

Place it **before** the auth middleware group (line 7, before `Route::livewire('/login')`). This must be accessible without authentication for the deploy health check.

**Verify**: `curl -f http://localhost:8000/health` returns `{"status":"ok"}` with HTTP 200.

### Step 2 — Fix web server mismatch in deploy.yml

**File**: `.github/workflows/deploy.yml:132-133`

Change:
```yaml
- name: Reload Apache
  run: sudo systemctl reload apache2
```

To:
```yaml
- name: Reload nginx
  run: sudo systemctl reload nginx
```

> **Decision needed**: Verify which web server the production host actually
> runs. If the self-hosted runner is behind a specific server (e.g., nginx
> with php-fpm), use that. If unsure, add a conditional:
> ```yaml
> - name: Reload web server
>   run: |
>     if systemctl is-active --quiet nginx; then sudo systemctl reload nginx
>     elif systemctl is-active --quiet apache2; then sudo systemctl reload apache2
>     fi
> ```

### Step 3 — Fix .gitignore for composer.lock

**File**: `.gitignore:35`

Change:
```
*.lock
```
To:
```
yarn.lock
```

`*.lock` was excluding `composer.lock`. We want to keep `package-lock.json` tracked (it's already on line 36), and we want `composer.lock` tracked for reproducible builds.

After making this change:
1. `git add composer.lock`
2. `git commit -m "chore: track composer.lock for reproducible builds"`

**Impact**: CI cache key at `deploy.yml:48` (`hashFiles('composer.lock')`) will now work correctly.

### Step 4 — Move laravel/boost to require-dev

**File**: `composer.json`

Remove from `require` (line 13):
```json
"laravel/boost": "^2.7",
```

Add to `require-dev` (after existing entries):
```json
"laravel/boost": "^2.7",
```

Then run `composer update laravel/boost --lock` to update the lockfile.

> **Note**: Verify that no production code imports from `Laravel\Boost`.
> Search with: `grep -rn "Laravel\\\\Boost\|laravel/boost" app/ routes/ config/`
> Expected: zero matches (it's an MCP dev tool only).

### Step 5 — Fix morilog/jalali version constraint

**File**: `composer.json:19`

Change:
```json
"morilog/jalali": "3.5"
```
To:
```json
"morilog/jalali": "^3.5"
```

Then run `composer update morilog/jalali --lock` to update the lockfile.

### Step 6 — Add PHPStan baseline size CI check

Add a new step to `.github/workflows/deploy.yml` in the **test** job, after the "Run tests" step:

```yaml
      - name: Check PHPStan baseline size
        run: |
          BASELINE_SIZE=$(wc -c < phpstan-baseline.neon)
          MAX_SIZE=300000  # 300KB — grow slowly, investigate when exceeded
          echo "Baseline size: ${BASELINE_SIZE} bytes (max: ${MAX_SIZE})"
          if [ "$BASELINE_SIZE" -gt "$MAX_SIZE" ]; then
            echo "❌ PHPStan baseline exceeds ${MAX_SIZE} bytes. Fix errors instead of suppressing."
            exit 1
          fi
```

> **Threshold rationale**: Current baseline is 267KB (267,541 bytes). Setting
> 300KB gives ~12% headroom. Adjust as needed. The goal is to prevent
> unchecked growth, not to block on the exact current size.

## 4. Files changed

| File | Action |
|------|--------|
| `routes/web.php` | **Modify** — add `/health` route |
| `.github/workflows/deploy.yml` | **Modify** — fix web server reload, add baseline check |
| `.gitignore` | **Modify** — change `*.lock` to `yarn.lock` |
| `composer.json` | **Modify** — move boost to require-dev, fix jalali constraint |
| `composer.lock` | **Auto-updated** by composer |

## 5. Verification

1. `composer validate --strict` — passes.
2. `composer install` — works with lockfile.
3. `phpstan analyse --no-progress` — passes.
4. `grep -rn "Laravel\\\\Boost\|use Laravel\\\\Boost" app/ routes/ config/` — zero matches (confirms boost is dev-only).
5. `curl -f http://localhost:8000/health` — returns HTTP 200 with `{"status":"ok"}`.
6. `wc -c phpstan-baseline.neon` — below 300,000 bytes.

## 6. Risks and mitigations

| Risk | Mitigation |
|------|------------|
| laravel/boost removal breaks runtime | Search for `use Laravel\Boost` in production code first. It's a dev tool — expected to have zero prod references. |
| Health check route conflicts with auth | Placed outside all middleware groups, so no auth required. |
| Web server mismatch causes deploy failure | Verify production server type before deploying. Use the conditional fallback if uncertain. |
| composer.lock commit creates large diff | One-time commit. Subsequent lockfile updates are small diffs. |
| Baseline size check blocks CI | Current 267KB is below the 300KB threshold. Only blocks if someone adds >33KB of new suppressions. |
