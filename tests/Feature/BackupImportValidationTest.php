<?php

use App\Exceptions\BackupValidationException;
use App\Models\Person;
use App\Models\User;
use App\Services\Backup\BackupArchiveReader;
use App\Services\Backup\BackupImportValidator;
use App\Services\Backup\BackupService;
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
 * @return array<string, mixed>
 */
function backupRow(string $table, array $overrides = []): array
{
    $row = (array) DB::table($table)->first();

    return array_merge($row, $overrides);
}

test('a users file whose persons are missing is rejected with the missing values', function () {
    $rows = [[
        'id' => 1,
        'n_code' => '9999999999',
        'password' => 'secret',
        'deleted_at' => null,
        'remember_token' => null,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'settings' => null,
    ]];

    $exception = null;

    try {
        app(BackupImportValidator::class)->validateTable('users', $rows);
    } catch (BackupValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('users');
    expect($exception->getMessage())->toContain('n_code');
    expect($exception->getMessage())->toContain('9999999999');
    expect($exception->getMessage())->toContain('persons');
});

test('a rejected file leaves the table untouched', function () {
    $before = DB::table('users')->count();

    try {
        app(BackupImportValidator::class)->validateTable('users', [[
            'id' => 1,
            'n_code' => '9999999999',
            'password' => 'secret',
            'deleted_at' => null,
            'remember_token' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'settings' => null,
        ]]);
    } catch (BackupValidationException) {
        // expected
    }

    expect(DB::table('users')->count())->toBe($before);
});

test('a valid table passes validation', function () {
    $user = User::factory()->create(['id' => 810]);

    expect(fn () => app(BackupImportValidator::class)->validateTable('users', [backupRow('users', ['id' => $user->id])]))
        ->not->toThrow(BackupValidationException::class);
});

test('a parent file is rejected when a live child points at a row the file does not have', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 900, 'n_code' => $person->n_code]);

    // The file carries a DIFFERENT person, so the live user would be orphaned.
    $fileRow = backupRow('persons', ['n_code' => '0987654321']);

    $exception = null;

    try {
        app(BackupImportValidator::class)->validateTable('persons', [$fileRow]);
    } catch (BackupValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('users');
    expect($exception->getMessage())->toContain('1234567890');
    expect($exception->getMessage())->toContain('persons');
});

test('a row with a column the table does not have is rejected', function () {
    $exception = null;

    try {
        app(BackupImportValidator::class)->validateTable('users', [['id' => 1, 'nope' => 'x']]);
    } catch (BackupValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('users');
    expect($exception->getMessage())->toContain('nope');
});

test('a full backup is validated against itself', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 920, 'n_code' => $person->n_code]);

    // A REAL full archive, with one users row pointing at a person the file
    // does not carry.
    $archive = app(BackupService::class)->exportAll();
    $contents = app(BackupArchiveReader::class)->read($archive->path);

    foreach ($contents['tables']['users'] as $index => $row) {
        $contents['tables']['users'][$index]['n_code'] = '1111111111';
    }

    $exception = null;

    try {
        app(BackupImportValidator::class)->validateFullBackup($contents['tables']);
    } catch (BackupValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('users');
    expect($exception->getMessage())->toContain('n_code');
    expect($exception->getMessage())->toContain('1111111111');
    expect($exception->getMessage())->toContain('persons');
});

test('a file that is not a zip is rejected', function () {
    $path = storage_path('app/private/backup/not-a-zip.zip');
    file_put_contents($path, 'this is not a zip');

    expect(fn () => app(BackupArchiveReader::class)->read($path))
        ->toThrow(BackupValidationException::class);
});

test('an archive with broken json is rejected', function () {
    $path = $this->backupMakeZip(
        storage_path('app/private/backup/broken.zip'),
        ['users.json' => '{not json']
    );

    expect(fn () => app(BackupArchiveReader::class)->read($path))
        ->toThrow(BackupValidationException::class);
});

test('an archive with an unknown table is rejected', function () {
    $path = $this->backupMakeZip(
        storage_path('app/private/backup/unknown.zip'),
        ['evil_table.json' => '[]']
    );

    expect(fn () => app(BackupArchiveReader::class)->read($path))
        ->toThrow(BackupValidationException::class);
});
