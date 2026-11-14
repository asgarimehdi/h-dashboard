<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Support\Backup\TableRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seedLookupTables();

    // The "leaves no temp file behind" tests glob the backup directory, so it
    // must belong to this test alone: four parallel workers share the real one.
    $this->useIsolatedBackupStorage();
});

afterEach(function () {
    $this->removeIsolatedBackupStorage();
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

test('every entry of a full export is canonical compact json', function () {
    User::factory()->create(['id' => 501, 'settings' => ['theme' => 'dark']]);
    // A Persian name: proves the payload does not escape its characters to \uXXXX.
    Unit::factory()->create(['name' => 'مرکز بهداشت شماره یک']);

    $entries = $this->backupZipEntries(app(BackupService::class)->exportAll()->path);

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $name => $payload) {
        $canonical = json_encode(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        expect($canonical)->toBe($payload, "The entry «{$name}» is not canonical compact JSON.");
    }
});

test('a full export holds the id ordered rows of every table', function () {
    // Inserted out of id order on purpose: an exporter that reads heap order
    // (no ORDER BY) returns 602 before 601 and fails the comparison below.
    User::factory()->create(['id' => 602]);
    User::factory()->create(['id' => 601]);

    $archive = app(BackupService::class)->exportAll();
    $entries = $this->backupZipEntries($archive->path);
    $manifest = $this->backupZipJson($archive->path, 'manifest.json');

    foreach (app(TableRegistry::class)->tables() as $table) {
        $expected = array_map(
            fn (object $row): array => (array) $row,
            DB::table($table)->orderBy('id')->get()->all()
        );

        expect($entries)->toHaveKey($table.'.json');

        expect(json_decode($entries[$table.'.json'], true, 512, JSON_THROW_ON_ERROR))
            ->toEqual($expected, "The «{$table}» entry is not the id ordered table content.");

        expect(collect($manifest['tables'])->firstWhere('table', $table)['rows'])
            ->toBe(count($expected));
    }

    $users = json_decode($entries['users.json'], true, 512, JSON_THROW_ON_ERROR);

    expect(array_column($users, 'id'))->toBe([601, 602]);
});

test('a table payload is encoded byte for byte in the pinned canonical form', function () {
    Person::factory()->create(['n_code' => '1234567890']);
    Person::factory()->create(['n_code' => '1234567891']);

    DB::table('users')->insert([
        [
            'id' => 501,
            'n_code' => '1234567890',
            'password' => 'hash-one',
            'deleted_at' => null,
            'remember_token' => 'token',
            'created_at' => '2026-01-02 03:04:05',
            'updated_at' => '2026-01-02 03:04:05',
            'settings' => '{"theme":"dark","path":"a/b"}',
        ],
        [
            'id' => 502,
            'n_code' => '1234567891',
            'password' => 'hash-two',
            'deleted_at' => '2026-02-03 04:05:06',
            'remember_token' => null,
            'created_at' => '2026-01-02 03:04:05',
            'updated_at' => '2026-01-02 03:04:05',
            'settings' => null,
        ],
    ]);

    $payload = $this->backupZipEntries(app(BackupService::class)->exportTable('users')->path)['users.json'];

    expect($payload)->toBe(
        '[{"id":501,"n_code":"1234567890","password":"hash-one","deleted_at":null,'
        .'"remember_token":"token","created_at":"2026-01-02 03:04:05","updated_at":"2026-01-02 03:04:05",'
        .'"settings":"{\"theme\":\"dark\",\"path\":\"a/b\"}"},'
        .'{"id":502,"n_code":"1234567891","password":"hash-two","deleted_at":"2026-02-03 04:05:06",'
        .'"remember_token":null,"created_at":"2026-01-02 03:04:05","updated_at":"2026-01-02 03:04:05",'
        .'"settings":null}]'
    );
});

test('a failing table read leaves no temp file behind', function () {
    // Paratest runs this file in 4 processes against ONE shared
    // storage/app/private/backup, and a concurrent `exportAll()` stages its own
    // `.part` files there — so the directory-wide diff below saw foreign files
    // (the leftovers that broke CI all shared a single foreign run id) and
    // failed at random. Give this test its own storage root: then the diff can
    // only ever see files this export created.
    $storageRoot = sys_get_temp_dir().'/h-dashboard-backup-'.getmypid().'-'.uniqid();
    $this->app->useStoragePath($storageRoot);

    try {
        $directory = storage_path('app/private/backup');
        $before = glob($directory.'/*') ?: [];

        // The first table is read and staged, the second one does not exist, so
        // the export fails after it already wrote a temp file.
        $this->app->instance(TableRegistry::class, new class extends TableRegistry
        {
            public function tables(): array
            {
                return ['hardwares', 'a_table_that_does_not_exist'];
            }
        });

        expect(fn () => app(BackupService::class)->exportAll())->toThrow(QueryException::class);

        expect(array_values(array_diff(glob($directory.'/*') ?: [], $before)))->toBe([]);
    } finally {
        File::deleteDirectory($storageRoot);
    }
});

test('a failure while zipping the staged files leaves no partial archive behind', function () {
    $directory = storage_path('app/private/backup');

    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("The backup directory could not be created: {$directory}");
    }

    $filename = 'backup-full-20260101-000000-failuretest.zip';
    $staged = $directory.'/'.$filename.'.users.json.part';

    // The name is fixed so the test can look for it: drop any leftover first.
    @unlink($directory.'/'.$filename);
    @unlink($directory.'/'.$filename.'.part');

    if (file_put_contents($staged, '[]') === false) {
        throw new RuntimeException("The staged file could not be written: {$staged}");
    }

    try {
        $writeArchive = new ReflectionMethod(BackupService::class, 'writeArchive');

        // One staged entry exists, the second one does not: the zip cannot be
        // completed, and neither the zip nor its `.part` may survive.
        expect(fn () => $writeArchive->invoke(
            app(BackupService::class),
            $filename,
            ['users.json' => $staged, 'units.json' => $directory.'/a-missing-staged-file.json.part'],
            '{}'
        ))->toThrow(RuntimeException::class);

        expect(is_file($directory.'/'.$filename))->toBeFalse();
        expect(is_file($directory.'/'.$filename.'.part'))->toBeFalse();
    } finally {
        @unlink($staged);
        @unlink($directory.'/'.$filename);
        @unlink($directory.'/'.$filename.'.part');
    }
});
