<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1692 — a backup archive must not outlive the download that served it,
 * and `backup:prune-archives` must sweep only the stale leftovers.
 *
 * The test client never sends a BinaryFileResponse body, so `deleteFileAfterSend`
 * only runs inside `streamedContent()`. An `assertOk()`-only test would see the
 * file still on disk and fail for the wrong reason.
 */
uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->useIsolatedBackupStorage();
});

afterEach(function () {
    $this->removeIsolatedBackupStorage();
});

/**
 * Every archive the service has written to the isolated directory.
 *
 * @return list<string>
 */
function archivedBackupFiles(): array
{
    return glob(storage_path('app/private/backup').'/backup-*') ?: [];
}

/**
 * Drop a real file in the backup directory, back-dated by the given days.
 */
function seedBackupFile(string $name, int $ageInDays, string $contents = 'PK'): string
{
    $path = storage_path('app/private/backup').'/'.$name;

    file_put_contents($path, $contents);
    touch($path, now()->subDays($ageInDays)->getTimestamp());

    return $path;
}

test('a full backup download leaves no archive on disk', function () {
    $response = $this->actingAs($this->admin)->get(route('backup.full'));

    $response->assertOk()->assertDownload();

    // The archive exists while the response is being built…
    expect(archivedBackupFiles())->toHaveCount(1);

    // …and is gone once the body has been sent.
    $content = $response->streamedContent();

    expect($content)->toStartWith('PK');
    expect(archivedBackupFiles())->toBe([]);
});

test('a table backup download leaves no archive on disk', function () {
    $response = $this->actingAs($this->admin)->get(route('backup.table', 'users'));

    $response->assertOk()->assertDownload();

    expect(glob(storage_path('app/private/backup').'/backup-users-*.zip'))->toHaveCount(1);

    $content = $response->streamedContent();

    expect($content)->toStartWith('PK');
    expect(archivedBackupFiles())->toBe([]);
});

test('two downloads in a row both yield a valid archive', function () {
    foreach ([1, 2] as $attempt) {
        $response = $this->actingAs($this->admin)->get(route('backup.table', 'users'));

        $response->assertOk()->assertDownload();

        expect($response->streamedContent())->toStartWith('PK', "download {$attempt} must be a zip");
        expect(archivedBackupFiles())->toBe([], "download {$attempt} must leave no archive behind");
    }
});

test('the prune removes only archives older than the retention window', function () {
    $oldArchive = seedBackupFile('backup-full-20250101-000000-aaaaaaaa.zip', 8);
    $oldPartial = seedBackupFile('backup-users-20250101-000000-bbbbbbbb.json.part', 8);
    $freshArchive = seedBackupFile('backup-users-20260101-000000-cccccccc.zip', 0);
    $foreignFile = seedBackupFile('manual-restore.zip', 30);

    $this->artisan('backup:prune-archives')->assertExitCode(0);

    expect(file_exists($oldArchive))->toBeFalse();
    expect(file_exists($oldPartial))->toBeFalse();
    expect(file_exists($freshArchive))->toBeTrue();
    expect(file_exists($foreignFile))->toBeTrue();
});

test('a dry run reports the stale archives without deleting them', function () {
    $oldArchive = seedBackupFile('backup-full-20250101-000000-aaaaaaaa.zip', 8);
    $freshArchive = seedBackupFile('backup-users-20260101-000000-cccccccc.zip', 0);

    $this->artisan('backup:prune-archives', ['--dry-run' => true])
        ->expectsOutputToContain('backup-full-20250101-000000-aaaaaaaa.zip')
        ->assertExitCode(0);

    expect(file_exists($oldArchive))->toBeTrue();
    expect(file_exists($freshArchive))->toBeTrue();
});

test('a longer retention window keeps an eight day old archive', function () {
    $oldArchive = seedBackupFile('backup-full-20250101-000000-aaaaaaaa.zip', 8);

    $this->artisan('backup:prune-archives', ['--days' => 30])->assertExitCode(0);

    expect(file_exists($oldArchive))->toBeTrue();
});

test('a retention window below one day is refused', function () {
    $oldArchive = seedBackupFile('backup-full-20250101-000000-aaaaaaaa.zip', 8);

    $this->artisan('backup:prune-archives', ['--days' => 0])->assertExitCode(1);

    expect(file_exists($oldArchive))->toBeTrue();
});

test('a real prune writes one backup activity log row, a dry run writes none', function () {
    seedBackupFile('backup-full-20250101-000000-aaaaaaaa.zip', 8);

    $this->artisan('backup:prune-archives', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('activity_logs')->where('type', 'backup')->count())->toBe(0);

    $this->artisan('backup:prune-archives')->assertExitCode(0);

    $row = DB::table('activity_logs')->where('type', 'backup')->sole();

    expect($row->user_id)->toBeNull();
    expect($row->description)->toContain('حذف شد');
});
