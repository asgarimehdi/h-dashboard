<?php

use App\Models\Person;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Support\Backup\TableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seedLookupTables();
});

test('table export returns exactly the seeded rows with every column', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 501, 'n_code' => $person->n_code, 'settings' => ['theme' => 'dark']]);
    User::factory()->create(['id' => 502]);

    $archive = app(BackupService::class)->exportTable('users');

    $rows = $this->backupZipJson($archive->path, 'users.json');

    expect($rows)->toHaveCount(2);
    expect(array_keys($rows[0]))->toEqualCanonicalizing(app(TableRegistry::class)->columns('users'));

    $exported = collect($rows)->firstWhere('id', 501);

    expect($exported)->not->toBeNull();
    expect($exported['n_code'])->toBe('1234567890');
    expect($exported['deleted_at'])->toBeNull();
    expect(json_decode((string) $exported['settings'], true))->toBe(['theme' => 'dark']);
});

test('table export keeps soft deleted rows', function () {
    $user = User::factory()->create(['id' => 601]);
    $user->delete();

    $rows = $this->backupZipJson(app(BackupService::class)->exportTable('users')->path, 'users.json');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['id'])->toBe(601);
    expect($rows[0]['deleted_at'])->not->toBeNull();
});

test('an empty table exports an empty array', function () {
    expect(DB::table('zabbix_sync_logs')->count())->toBe(0);

    $rows = $this->backupZipJson(
        app(BackupService::class)->exportTable('zabbix_sync_logs')->path,
        'zabbix_sync_logs.json'
    );

    expect($rows)->toBe([]);
});

test('two table exports in the same second do not write the same file', function () {
    // The clock is frozen: both exports land in the same second, so a name
    // built from the timestamp alone would be the same file twice.
    $this->freezeTime();

    $service = app(BackupService::class);

    User::factory()->create(['id' => 701]);
    $first = $service->exportTable('users');

    User::factory()->create(['id' => 702]);
    $second = $service->exportTable('users');

    expect($first->path)->not->toBe($second->path);

    // ... and the first archive still holds ITS snapshot.
    expect($this->backupZipJson($first->path, 'users.json'))->toHaveCount(1);
    expect($this->backupZipJson($second->path, 'users.json'))->toHaveCount(2);
});

test('two full exports in the same second do not write the same file', function () {
    $this->freezeTime();

    $service = app(BackupService::class);

    User::factory()->create(['id' => 701]);
    $first = $service->exportAll();

    User::factory()->create(['id' => 702]);
    $second = $service->exportAll();

    expect($first->path)->not->toBe($second->path);

    expect($this->backupZipJson($first->path, 'users.json'))->toHaveCount(1);
    expect($this->backupZipJson($second->path, 'users.json'))->toHaveCount(2);
});

test('full export holds one json file per registered table plus the manifest', function () {
    $archive = app(BackupService::class)->exportAll();

    $expected = array_map(fn (string $table) => $table.'.json', app(TableRegistry::class)->tables());
    $expected[] = 'manifest.json';
    sort($expected);

    $actual = array_keys($this->backupZipEntries($archive->path));
    sort($actual);

    expect($actual)->toEqual($expected);
});

test('the manifest matches the pinned schema', function () {
    User::factory()->create(['id' => 701]);
    User::factory()->create(['id' => 702]);

    $manifest = $this->backupZipJson(app(BackupService::class)->exportAll()->path, 'manifest.json');

    expect($manifest)->toHaveKeys(['version', 'exported_at', 'app_version', 'tables']);
    expect($manifest['version'])->toBe(1);
    expect($manifest['exported_at'])->toBeString();
    expect($manifest['app_version'])->toBeString()->toMatch('/^[0-9a-f]{40}$/');
    expect($manifest['tables'])->toHaveCount(32);

    foreach ($manifest['tables'] as $entry) {
        expect($entry)->toHaveKeys(['table', 'rows']);
        expect($entry['table'])->toBeString();
        expect($entry['rows'])->toBeInt();
    }

    $users = collect($manifest['tables'])->firstWhere('table', 'users');
    expect($users['rows'])->toBe(2);
});

test('full export reads every table from one repeatable read snapshot', function () {
    $observed = [];

    DB::listen(function ($query) use (&$observed) {
        $observed[] = ['sql' => $query->sql, 'level' => DB::connection()->transactionLevel()];
    });

    // The isolation level can only be set by the code that STARTS the
    // transaction. The test's own transaction (RefreshDatabase) is already
    // open, so it is closed here — the export then opens the transaction it
    // reads in, exactly as it does in production.
    DB::rollBack();

    app(BackupService::class)->exportAll();

    $isolation = collect($observed)->first(fn (array $query) => str_contains($query['sql'], 'ISOLATION LEVEL'));

    expect($isolation)->not->toBeNull();
    expect($isolation['sql'])->toContain('REPEATABLE READ');
    expect($isolation['sql'])->toContain('READ ONLY');

    $reads = array_values(array_filter(
        $observed,
        fn (array $query) => str_starts_with(strtolower(ltrim($query['sql'])), 'select')
    ));

    expect($reads)->not->toBeEmpty();

    foreach ($reads as $read) {
        expect($read['level'])->toBe(1, 'Every read of the full backup must happen inside the snapshot transaction.');
    }
});
