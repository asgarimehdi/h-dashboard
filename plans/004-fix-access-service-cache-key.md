# Plan 004: Fix AccessService cache key to include session context
Drift: git diff --stat 25c206a4..HEAD -- app/Services/ app/Services/
Commit: 25c206a4 | Priority: P2 | Effort: S | Risk: MED | Category: correctness

## Why
Cache key omits session(current_unit_id). Multi-unit user switching context gets stale unit IDs. Cache serves wrong data.

## Current state
app/Services/AccessService.php:43 — $cacheKey = "accessible_units:{$user->id}:".md5(...)
Missing: session(current_unit_id) in the key.

## Commands
php artisan tinker --execute="..."
php artisan cache:clear
vendor/bin/pint --dirty

## Steps
### 1. Fix accessibleUnitIds() cache key
In app/Services/AccessService.php:43, add $sessionUnitId = session('current_unit_id', 'none'); to the cache key:
$cacheKey = "accessible_units:{$user->id}:{$sessionUnitId}:".md5(json_encode($baseUnitIds));
Verify: grep -n "sessionUnitId" app/Services/AccessService.php (2 matches)

### 2. Fix clearCache() to match
In app/Services/AccessService.php:66, use same pattern.
Verify: vendor/bin/pint app/Services/AccessService.php exits 0

### 3. Operator: clear cache
php artisan cache:clear
Old cache entries orphaned (TTL 30min handles, but clear explicitly).

## Done
- [ ] git diff app/Services/AccessService.php shows sessionUnitId in both methods
- [ ] php artisan tinker --execute works (with auth user)
- [ ] vendor/bin/pint --dirty exits 0

## STOP
Stop if session() helper unavailable in service context. Stop if md5 key changes format significantly (acceptable but note in PR).