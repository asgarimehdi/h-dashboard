<?php

use App\Console\Commands\PruneNullActorActivityLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1809 — queue/scheduler writers, `logAs(null, …)` on restore and
 * `ON DELETE SET NULL` all leave `user_id = null`, and `whereIn('user_id', …)`
 * can never match NULL. This sweep is the production trigger for those rows:
 * it must delete ONLY null-actor rows past the window, leaving user-owned rows
 * (and therefore the 12-month `data:archive` policy) untouched.
 */
covers(PruneNullActorActivityLogs::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Insert an activity row back-dated by the given days and return its id.
 */
function seedActivityLogRow(?int $userId, int $ageInDays, string $type = 'system', string $description = 'seed'): int
{
    return DB::table('activity_logs')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'description' => $description,
        'created_at' => now()->subDays($ageInDays),
        'updated_at' => now()->subDays($ageInDays),
    ]);
}

test('the default window deletes only null-actor rows older than ninety days', function () {
    ['user' => $user] = $this->createUserWithUnit();

    $oldNull = seedActivityLogRow(null, 100);
    $freshNull = seedActivityLogRow(null, 10);
    $oldUser = seedActivityLogRow($user->id, 100, 'login');
    $freshUser = seedActivityLogRow($user->id, 10, 'login');

    $this->artisan('activity:prune-null-actor')->assertExitCode(0);

    $this->assertDatabaseMissing('activity_logs', ['id' => $oldNull]);
    $this->assertDatabaseHas('activity_logs', ['id' => $freshNull]);
    // User-owned rows are never candidates — this is the whole point of #1809
    // staying a separate command instead of an org-wide archive run.
    $this->assertDatabaseHas('activity_logs', ['id' => $oldUser]);
    $this->assertDatabaseHas('activity_logs', ['id' => $freshUser]);
});

test('a null-actor row exactly on the cutoff is kept', function () {
    $this->freezeTime();

    $onCutoff = seedActivityLogRow(null, 90);

    $this->artisan('activity:prune-null-actor')->assertExitCode(0);

    $this->assertDatabaseHas('activity_logs', ['id' => $onCutoff]);
});

test('the days option narrows or widens the window', function () {
    $this->freezeTime();

    $fortyDays = seedActivityLogRow(null, 40);
    $twentyDays = seedActivityLogRow(null, 20);

    $this->artisan('activity:prune-null-actor', ['--days' => 30])->assertExitCode(0);

    $this->assertDatabaseMissing('activity_logs', ['id' => $fortyDays]);
    $this->assertDatabaseHas('activity_logs', ['id' => $twentyDays]);
});

test('a dry run reports the count without deleting or auditing', function () {
    $oldNull = seedActivityLogRow(null, 100, 'queue');

    $this->artisan('activity:prune-null-actor', ['--dry-run' => true])
        ->expectsOutputToContain('قابل حذف')
        ->assertExitCode(0);

    $this->assertDatabaseHas('activity_logs', ['id' => $oldNull]);
    expect(DB::table('activity_logs')->where('type', 'system')->count())->toBe(0);
});

test('a real sweep writes one null-actor system activity row', function () {
    ['user' => $user] = $this->createUserWithUnit();

    seedActivityLogRow(null, 100, 'queue');
    seedActivityLogRow($user->id, 100, 'login');

    $this->artisan('activity:prune-null-actor')->assertExitCode(0);

    $row = DB::table('activity_logs')->where('type', 'system')->sole();

    expect($row->user_id)->toBeNull();
    expect($row->description)->toContain('حذف شد');
});

test('an empty table is a clean no-op', function () {
    $this->artisan('activity:prune-null-actor')->assertExitCode(0);

    expect(DB::table('activity_logs')->count())->toBe(0);
});

test('a retention window below thirty days is refused', function () {
    $oldNull = seedActivityLogRow(null, 100);

    $this->artisan('activity:prune-null-actor', ['--days' => 29])->assertExitCode(1);

    $this->assertDatabaseHas('activity_logs', ['id' => $oldNull]);
});
