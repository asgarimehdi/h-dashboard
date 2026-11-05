<?php

use App\Exceptions\BackupValidationException;
use App\Models\Person;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seedLookupTables();
});

/**
 * The rows of a table, in the order and shape the backup writes them.
 *
 * @return list<array<string, mixed>>
 */
function backupDbRows(string $table): array
{
    $rows = [];

    foreach (DB::table($table)->orderBy('id')->get() as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

test('importing a table after its parents makes the table exactly equal to the file', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 930, 'n_code' => $person->n_code]);
    User::factory()->create(['id' => 931]);

    $archive = app(BackupService::class)->exportTable('users');
    $fileRows = $this->backupZipJson($archive->path, 'users.json');

    // The file carries its rows in the order the database handed them out;
    // the comparison is by id, so the order cannot decide the outcome.
    usort($fileRows, fn (array $a, array $b) => $a['id'] <=> $b['id']);

    // One row disappears from the database — the file still carries it.
    DB::table('users')->where('id', 931)->delete();

    app(RestoreService::class)->restoreArchive($archive->path);

    expect(DB::table('users')->count())->toBe(count($fileRows));
    expect(json_encode(backupDbRows('users')))->toBe(json_encode($fileRows));
});

test('a rejected file leaves the table untouched', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 940, 'n_code' => $person->n_code]);

    $before = json_encode(backupDbRows('users'));

    $archive = app(BackupService::class)->exportTable('users');
    $rows = $this->backupZipJson($archive->path, 'users.json');

    // One bad row: the person it points at does not exist.
    $rows[0]['n_code'] = '9999999999';

    $broken = $this->backupMakeZip(
        storage_path('app/private/backup/broken-users.zip'),
        ['users.json' => json_encode($rows)]
    );

    expect(fn () => app(RestoreService::class)->restoreArchive($broken))
        ->toThrow(BackupValidationException::class);

    expect(json_encode(backupDbRows('users')))->toBe($before);
});

test('a file that is not a zip fails without any change', function () {
    $path = storage_path('app/private/backup/garbage.zip');
    file_put_contents($path, 'not a zip at all');

    $before = DB::table('users')->count();

    expect(fn () => app(RestoreService::class)->restoreArchive($path))
        ->toThrow(BackupValidationException::class);

    expect(DB::table('users')->count())->toBe($before);
});

test('after a restore with explicit ids the next create gets a fresh id', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 1001, 'n_code' => $person->n_code]);
    User::factory()->create(['id' => 1002]);

    $archive = app(BackupService::class)->exportTable('users');

    DB::table('users')->delete();

    app(RestoreService::class)->restoreArchive($archive->path);

    $fresh = User::factory()->create();

    expect($fresh->id)->toBeGreaterThan(1002);
    expect(DB::table('users')->where('id', $fresh->id)->count())->toBe(1);
});

test('restoring one table does not touch the other tables', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 950, 'n_code' => $person->n_code]);

    $personsBefore = DB::table('persons')->count();
    $lookupsBefore = DB::table('tahsils')->count();

    $archive = app(BackupService::class)->exportTable('users');

    app(RestoreService::class)->restoreArchive($archive->path);

    expect(DB::table('persons')->count())->toBe($personsBefore);
    expect(DB::table('tahsils')->count())->toBe($lookupsBefore);
});

test('a file that carries the same id twice is rejected', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 960, 'n_code' => $person->n_code]);

    $archive = app(BackupService::class)->exportTable('users');
    $rows = $this->backupZipJson($archive->path, 'users.json');

    // The same primary key twice: which of the two rows wins is undefined,
    // so the file is refused instead of silently losing one of them.
    $rows[] = $rows[0];

    $broken = $this->backupMakeZip(
        storage_path('app/private/backup/duplicate-users.zip'),
        ['users.json' => json_encode($rows)]
    );

    expect(fn () => app(RestoreService::class)->restoreArchive($broken))
        ->toThrow(BackupValidationException::class);

    expect(DB::table('users')->where('id', 960)->count())->toBe(1);
});
