# Plan 013: Fix activity_logs CASCADE Delete — Change to SET NULL

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** High  
**Category:** Data Integrity  

## Problem

The `activity_logs` table has a foreign key on `user_id` with `ON DELETE CASCADE`. When a user is deleted, **all their activity log entries are also deleted**. This destroys the audit trail — the very records needed for compliance, debugging, and accountability.

## Current State

**File:** `database/migrations/2026_07_03_000001_create_activity_logs_table.php:27-29`

```php
$table->foreign('user_id')
    ->references('id')->on('users')
    ->onDelete('cascade');
```

Activity logs are critical for:
- Audit compliance (healthcare regulations)
- Debugging user actions
- Tracking who performed changes
- Security incident investigation

Deleting them when a user account is removed destroys evidence.

## Proposed Fix

Create a new migration that drops the CASCADE FK and recreates it with `SET NULL`:

```php
// database/migrations/2026_09_02_000001_change_activity_logs_user_fk_to_set_null.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign('activity_logs_user_id_foreign');
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullable()          // Allow null for orphaned records
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }
};
```

**Key details:**
1. `user_id` column must be made nullable (currently `unsignedBigInteger` NOT NULL)
2. `SET NULL` preserves the activity log record but sets `user_id` to NULL
3. `nullable()` is needed because existing NULL values would violate the constraint

## Files to Modify

| File | Change |
|------|--------|
| `database/migrations/2026_09_02_000001_change_activity_logs_user_fk_to_set_null.php` | New migration: CASCADE → SET NULL |

**Out of scope:** The original migration (don't modify it), ActivityLog model, any code that queries activity_logs.

## Verification

```bash
# 1. Run the new migration
php artisan migrate
# Expected: Migration ran successfully

# 2. Verify FK behavior
php scripts/boost_tool.php query '{"sql": "SELECT contype, conname FROM pg_constraint WHERE conrelid = '\''activity_logs'\''::regclass AND conname LIKE '\''%user%'\''"}'
# Expected: contype = 'f' (foreign key), no CASCADE

# 3. Test: delete a user with activity logs
php artisan tinker --execute '
$user = App\Models\User::first();
$count = App\Models\ActivityLog::where("user_id", $user->id)->count();
$user->delete();
$remaining = App\Models\ActivityLog::whereNull("user_id")->count();
echo "Logs before: {$count}, Logs with null user_id after delete: {$remaining}";
'
# Expected: Logs preserved with user_id = NULL

# 4. Run full test suite
composer test
# Expected: 928+ pass
```

## Test Plan

```php
it('preserves activity_logs when user is deleted', function () {
    $user = User::factory()->create();
    
    // Create activity log entries for this user
    ActivityLog::factory()->count(3)->create(['user_id' => $user->id]);
    $logCount = ActivityLog::where('user_id', $user->id)->count();
    expect($logCount)->toBe(3);

    // Delete the user
    $user->delete();

    // Activity logs should still exist, with user_id = null
    $remainingLogs = ActivityLog::whereNull('user_id')->count();
    expect($remainingLogs)->toBe(3);
});
```

## STOP Conditions

- If there's code that relies on `user_id IS NOT NULL` for activity logs
- If the FK constraint name is different from `activity_logs_user_id_foreign` (check actual DB)
- If other tables have the same CASCADE issue (extend scope if needed)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| ActivityLog queries filter by user_id | May miss null-user records | Audit ActivityLog model scopes |
| FK constraint name differs | Migration fails | Check actual constraint name: `SELECT conname FROM pg_constraint WHERE ...` |
| Existing tests assume CASCADE | Test failures | Check test teardown code for user deletion |

## Maintenance Notes

- Consider applying the same SET NULL treatment to other audit tables
- Add `->withTrashed()` scopes to ActivityLog queries to find logs from soft-deleted users
- Document the audit trail retention policy
