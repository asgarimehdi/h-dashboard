<?php

use App\Exceptions\BackupValidationException;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\User;
use App\Services\Backup\ArchiveBackupSource;
use App\Services\Backup\BackupArchiveReader;
use App\Services\Backup\BackupImportValidator;
use App\Services\Backup\BackupService;
use App\Support\Backup\BackupProblem;
use App\Support\Backup\TableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seedLookupTables();
    File::ensureDirectoryExists(storage_path('app/private/backup'));
});

final class RecordingZipArchive extends ZipArchive
{
    public int $closes = 0;

    public function close(): bool
    {
        $this->closes++;

        return parent::close();
    }
}

final class RecordingArchiveReader extends BackupArchiveReader
{
    /** @var list<RecordingZipArchive> */
    public array $opened = [];

    protected function newZipArchive(): ZipArchive
    {
        return $this->opened[] = new RecordingZipArchive;
    }
}

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

test('a row without the id column is reported as a missing column', function () {
    User::factory()->create(['id' => 811]);

    // The missing `id` key is the validator's FIRST gate: it reports the
    // absent column instead of leaving the row to fail later in the restore.
    $row = array_diff_key(backupRow('users'), ['id' => true]);
    $caught = null;

    try {
        app(BackupImportValidator::class)->validateTable('users', [$row]);
    } catch (BackupValidationException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->problems[0]->kind)->toBe(BackupProblem::KIND_MISSING_COLUMN);
    expect($caught->problems[0]->column)->toBe('id');
    expect($caught->getMessage())->toContain('id');
});

test('a full backup is validated against itself', function () {
    $person = Person::factory()->create(['n_code' => '1234567890']);
    User::factory()->create(['id' => 920, 'n_code' => $person->n_code]);

    // A REAL full archive, with one users row pointing at a person the file
    // does not carry.
    $archive = app(BackupService::class)->exportAll();
    $reader = app(BackupArchiveReader::class);
    $tables = [];

    foreach ($reader->inspect($archive->path)['tables'] as $table) {
        $tables[$table] = iterator_to_array($reader->rows($archive->path, $table), false);
    }

    foreach ($tables['users'] as $index => $row) {
        $tables['users'][$index]['n_code'] = '1111111111';
    }

    $exception = null;

    try {
        app(BackupImportValidator::class)->validateFullBackup($tables);
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

    expect(fn () => app(BackupArchiveReader::class)->inspect($path))
        ->toThrow(BackupValidationException::class);
});

test('an archive with broken json is rejected', function () {
    $path = $this->backupMakeZip(
        storage_path('app/private/backup/broken.zip'),
        ['users.json' => '{not json']
    );

    $reader = app(BackupArchiveReader::class);

    // The reader's light inspection only validates the manifest; the table
    // body is rejected when its rows are streamed.
    expect(fn () => iterator_to_array($reader->rows($path, 'users')))
        ->toThrow(BackupValidationException::class);
});

test('an archive with an unknown table is rejected', function () {
    $path = $this->backupMakeZip(
        storage_path('app/private/backup/unknown.zip'),
        ['evil_table.json' => '[]']
    );

    expect(fn () => app(BackupArchiveReader::class)->inspect($path))
        ->toThrow(BackupValidationException::class);
});

test('the archive handle is closed on every failed read', function (array $files, string $message, string $mode) {
    $path = $this->backupMakeZip(
        storage_path('app/private/backup/reader-'.uniqid().'.zip'),
        $files
    );

    $reader = new RecordingArchiveReader(new TableRegistry);
    $caught = null;

    try {
        // `read()` is gone (#1418): manifest/registry/empty failures surface
        // from `inspect()`, malformed table bodies only when `rows()` streams.
        // `rows()` is a generator, so the archive is only opened (and the
        // failure only thrown) once the stream is consumed.
        if ($mode === 'rows') {
            iterator_to_array($reader->rows($path, 'users'));
        } else {
            $reader->inspect($path);
        }
    } catch (BackupValidationException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toBe($message)
        ->and($reader->opened)->toHaveCount(1)
        ->and($reader->opened[0]->closes)->toBe(1);
})->with([
    'invalid manifest' => [['manifest.json' => '{not json'], 'محتوای «manifest.json» در فایل بک‌آپ JSON معتبر نیست.', 'inspect'],
    // A document that is not a top-level array is refused by the stream
    // before the first row, so its message is the invalid-JSON one, not the
    // old decode() "must be an array of rows" message.
    'invalid table json' => [['users.json' => '{bad'], 'محتوای «users.json» در فایل بک‌آپ JSON معتبر نیست.', 'rows'],
    'non-array document' => [['users.json' => '{"a":1}'], 'محتوای «users.json» در فایل بک‌آپ JSON معتبر نیست.', 'rows'],
    'non-object row' => [['users.json' => '[1,2,3]'], 'محتوای «users.json» باید آرایه‌ای از ردیف‌ها باشد.', 'rows'],
    'unregistered table' => [['evil_table.json' => '[]'], 'جدول «evil_table» در رجیستری بک‌آپ نیست.', 'inspect'],
    'no tables' => [['notes.txt' => 'x'], 'فایل بک‌آپ هیچ جدولی ندارد.', 'inspect'],
]);

test('the archive handle is closed on success', function () {
    $path = $this->backupMakeZip(
        storage_path('app/private/backup/reader-'.uniqid().'.zip'),
        ['users.json' => '[{"id":1}]']
    );

    $reader = new RecordingArchiveReader(new TableRegistry);

    expect($reader->inspect($path))->toHaveKey('tables')
        ->and($reader->opened[0]->closes)->toBe(1);
});

/**
 * @return list<array{0: string, 1: string, 2: int|string|null, 3: string, 4: mixed, 5: string}>
 */
function backupProblemTuples(BackupValidationException $exception): array
{
    return array_map(
        fn (BackupProblem $problem): array => [
            $problem->kind,
            $problem->table,
            $problem->rowId,
            $problem->column,
            $problem->value,
            $problem->relatedTable,
        ],
        $exception->problems,
    );
}

test('the reader streams a large table without materialising it', function () {
    Hardware::factory()->create();

    $path = $this->backupMakeGeneratedZip(
        storage_path('app/private/backup/streaming-'.Str::random(8).'.zip'),
        'hardwares',
        25_000,
    );

    $reader = app(BackupArchiveReader::class);

    expect($reader->inspect($path)['tables'])->toBe(['hardwares']);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    $count = 0;

    foreach ($reader->rows($path, 'hardwares') as $row) {
        $count++;
    }

    $peak = memory_get_peak_usage(true);

    expect($count)->toBe(25_000);
    expect($peak - $before)->toBeLessThan(32 * 1024 * 1024);
});

test('chunked validation reports the same problems as the array path', function () {
    $person = Person::factory()->create(['n_code' => '1111111111']);
    $user = User::factory()->create(['id' => 800, 'n_code' => $person->n_code]);

    // A live user the persons file does not carry: the orphan-child case.
    Person::factory()->create(['n_code' => '2222222222']);
    User::factory()->create(['id' => 801, 'n_code' => '2222222222']);

    $validUser = (array) DB::table('users')->where('id', $user->id)->first();
    $validPerson = (array) DB::table('persons')->where('id', $person->id)->first();

    $cases = [
        'unknown column' => ['users', [array_merge($validUser, ['nope' => 'x'])]],
        'missing column' => ['users', [array_diff_key($validUser, ['id' => true])]],
        'duplicate id' => ['users', [$validUser, $validUser]],
        'missing reference' => ['users', [array_merge($validUser, ['n_code' => '9999999999'])]],
        'orphan child' => ['persons', [$validPerson]],
    ];

    $capture = function (callable $validate): ?BackupValidationException {
        try {
            $validate();
        } catch (BackupValidationException $exception) {
            return $exception;
        }

        return null;
    };

    foreach ($cases as $name => [$table, $rows]) {
        $arrayException = $capture(
            fn () => app(BackupImportValidator::class)->validateTable($table, $rows)
        );

        $path = $this->backupMakeZip(
            storage_path('app/private/backup/equivalence-'.Str::random(8).'.zip'),
            [$table.'.json' => json_encode($rows)]
        );

        $source = new ArchiveBackupSource(app(BackupArchiveReader::class), $path, [$table]);

        $streamException = $capture(
            fn () => app(BackupImportValidator::class)->validateTable($table, $source)
        );

        expect($arrayException, "{$name}: the array path did not throw")->not->toBeNull();
        expect($streamException, "{$name}: the stream path did not throw")->not->toBeNull();
        expect(backupProblemTuples($streamException))
            ->toBe(backupProblemTuples($arrayException), "{$name}: the two paths disagree");
    }
});

test('a duplicate id split across a chunk boundary is rejected', function () {
    Hardware::factory()->create();

    $path = $this->backupMakeGeneratedZip(
        storage_path('app/private/backup/duplicate-boundary-'.Str::random(8).'.zip'),
        'hardwares',
        501,
        function (int $index, array $row): array {
            // Positions 500 and 501: the last row of the first chunk and the
            // first row of the next one.
            if ($index === 500) {
                $row['id'] = 500;
            }

            return $row;
        },
    );

    $caught = null;

    try {
        app(BackupImportValidator::class)->validateTable(
            'hardwares',
            new ArchiveBackupSource(app(BackupArchiveReader::class), $path, ['hardwares'])
        );
    } catch (BackupValidationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->problems[0]->kind)->toBe(BackupProblem::KIND_DUPLICATE_ROW);
    expect($caught->problems[0]->rowId)->toBe(500);
});

test('a missing reference whose parent is in a later chunk is resolved', function () {
    for ($index = 1; $index <= 600; $index++) {
        Person::factory()->create(['n_code' => sprintf('1%09d', $index)]);
    }

    $last = Person::query()->where('n_code', sprintf('1%09d', 600))->firstOrFail();
    $person = Person::factory()->create(['n_code' => '3333333333']);
    $user = User::factory()->create(['id' => 850, 'n_code' => $person->n_code]);

    $row = (array) DB::table('users')->where('id', $user->id)->first();

    // The parent is the 600th person: it sits in the second `whereIn` chunk.
    expect(fn () => app(BackupImportValidator::class)->validateTable('users', [
        array_merge($row, ['n_code' => $last->n_code]),
    ]))->not->toThrow(BackupValidationException::class);

    $caught = null;

    try {
        app(BackupImportValidator::class)->validateTable('users', [
            array_merge($row, ['n_code' => '0000000000']),
        ]);
    } catch (BackupValidationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->problems[0]->kind)->toBe(BackupProblem::KIND_MISSING_REFERENCE);
    expect($caught->problems[0]->rowId)->toBe(850);
    expect($caught->problems[0]->value)->toBe('0000000000');
});

test('a live child is reported when the parent set spans multiple chunks', function () {
    Person::factory()->create(['n_code' => '1111111111']);
    Person::factory()->create(['n_code' => '2222222222']);
    User::factory()->create(['id' => 860, 'n_code' => '2222222222']);

    $path = $this->backupMakeGeneratedZip(
        storage_path('app/private/backup/live-child-'.Str::random(8).'.zip'),
        'persons',
        600,
        function (int $index, array $row): array {
            $row['n_code'] = sprintf('9%09d', $index + 1);

            return $row;
        },
    );

    $caught = null;

    try {
        app(BackupImportValidator::class)->validateTable(
            'persons',
            new ArchiveBackupSource(app(BackupArchiveReader::class), $path, ['persons'])
        );
    } catch (BackupValidationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->problems[0]->kind)->toBe(BackupProblem::KIND_ORPHAN_CHILD);
    expect($caught->problems[0]->table)->toBe('users');
    expect($caught->problems[0]->rowId)->toBe(860);
    expect($caught->problems[0]->value)->toBe('2222222222');
});
