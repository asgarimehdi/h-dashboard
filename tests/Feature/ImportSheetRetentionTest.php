<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #1759 — a failed import keeps its sheet so an operator can inspect which
 * rows caused the failure. `imports:prune-sheets` is the safety net that bounds
 * how long that sheet lives; it must only ever remove sheets older than the
 * retention window.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

/**
 * Drop a sheet in the import directory, back-dated by the given seconds.
 */
function seedImportSheet(string $path, int $ageInSeconds): string
{
    $disk = Storage::disk('local');

    $disk->put($path, "n_code\tpc_name\n");
    touch($disk->path($path), now()->subSeconds($ageInSeconds)->getTimestamp());

    return $path;
}

test('the default window prunes only sheets older than seven days', function () {
    seedImportSheet('imports/old-8d.csv', 8 * 86400);
    seedImportSheet('imports/fresh.csv', 0);

    $this->artisan('imports:prune-sheets')->assertExitCode(0);

    Storage::disk('local')->assertMissing('imports/old-8d.csv');
    Storage::disk('local')->assertExists('imports/fresh.csv');
});

test('a sheet exactly on the cutoff is kept', function () {
    $this->freezeTime();

    seedImportSheet('imports/edge-7d.csv', 7 * 86400);

    $this->artisan('imports:prune-sheets')->assertExitCode(0);

    Storage::disk('local')->assertExists('imports/edge-7d.csv');
});

test('the days option widens or narrows the window', function () {
    seedImportSheet('imports/old-3d.csv', 3 * 86400);
    seedImportSheet('imports/fresh-1h.csv', 3600);

    $this->artisan('imports:prune-sheets', ['--days' => 2])->assertExitCode(0);

    Storage::disk('local')->assertMissing('imports/old-3d.csv');
    Storage::disk('local')->assertExists('imports/fresh-1h.csv');
});

test('a dry run reports the stale sheets without deleting them', function () {
    seedImportSheet('imports/old-8d.csv', 8 * 86400);

    $this->artisan('imports:prune-sheets', ['--dry-run' => true])
        ->expectsOutputToContain('old-8d.csv')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists('imports/old-8d.csv');
    expect(DB::table('activity_logs')->where('type', 'import')->count())->toBe(0);
});

test('a real prune writes one import activity row', function () {
    seedImportSheet('imports/old-8d.csv', 8 * 86400);

    $this->artisan('imports:prune-sheets')->assertExitCode(0);

    $row = DB::table('activity_logs')->where('type', 'import')->sole();

    expect($row->user_id)->toBeNull();
    expect($row->description)->toContain('حذف شد');
});

test('a missing directory is a clean no-op', function () {
    $this->artisan('imports:prune-sheets')->assertExitCode(0);

    expect(DB::table('activity_logs')->where('type', 'import')->count())->toBe(0);
});

test('a retention window below one day is refused', function () {
    seedImportSheet('imports/old-8d.csv', 8 * 86400);

    $this->artisan('imports:prune-sheets', ['--days' => 0])->assertExitCode(1);

    Storage::disk('local')->assertExists('imports/old-8d.csv');
});
