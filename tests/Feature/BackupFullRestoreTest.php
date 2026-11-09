<?php

use App\Exceptions\BackupValidationException;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Support\Backup\TableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

/**
 * Seed a small but REAL graph: a unit tree, a person in a unit, a user
 * bound to that person, a ticket with a comment, and the rows that hang on
 * them. The full restore has to empty all 32 tables and fill them again —
 * with the foreign keys switched on — so the graph is what proves the
 * ordering.
 *
 * @return array<string, mixed>
 */
function seedBackupGraph(): array
{
    $unitType = UnitType::create(['name' => 'بیمارستان']);

    $parentUnit = Unit::create(['name' => 'اداره کل']);
    $childUnit = Unit::create([
        'name' => 'بیمارستان تست',
        'parent_id' => $parentUnit->id,
        'unit_type_id' => $unitType->id,
    ]);

    DB::table('unit_type_relationships')->insert([
        'child_unit_type_id' => $unitType->id,
        'allowed_parent_unit_type_id' => $unitType->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $person = Person::factory()->create([
        'n_code' => '1234567890',
        'u_id' => $childUnit->id,
    ]);

    $user = User::factory()->create(['id' => 1001, 'n_code' => $person->n_code]);

    DB::table('user_units')->insert([
        'user_id' => $user->id,
        'unit_id' => $childUnit->id,
        'role' => 'responsible',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $todo = Todo::factory()->create(['unit_id' => $childUnit->id]);
    DB::table('todos')->where('id', $todo->id)->update(['user_id' => $user->id]);

    $ticket = Ticket::factory()->create([
        'id' => 2001,
        'unit_id' => $childUnit->id,
        'user_id' => $user->id,
        'task_id' => $todo->id,
    ]);

    $commentId = DB::table('ticket_comments')->insertGetId([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'parent_id' => null,
        'body' => 'اولین نظر',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A reply — a self-referencing foreign key inside one table.
    DB::table('ticket_comments')->insert([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'parent_id' => $commentId,
        'body' => 'پاسخ',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('activity_logs')->insert([
        'user_id' => $user->id,
        'type' => 'created',
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'description' => 'تیکت ساخته شد',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'type' => 'ticket_created',
        'title' => 'تیکت جدید',
        'body' => 'یک تیکت جدید دارید',
        'icon' => 'o-bell',
        'color' => 'text-info',
        'is_read' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hardware = Hardware::factory()->create(['n_code' => $person->n_code]);

    DB::table('hardware_audits')->insert([
        'hardware_id' => $hardware->id,
        'user_id' => $user->id,
        'action' => 'created',
        'changes' => json_encode(['status' => ['قدیمی' => null, 'جدید' => 'فعال']]),
        'source' => 'web',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('daily_reports')->insert([
        'unit_id' => $childUnit->id,
        'report_date' => now()->toDateString(),
        'summary' => 'گزارش روزانه',
        'generated_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'unitType' => $unitType,
        'parentUnit' => $parentUnit,
        'childUnit' => $childUnit,
        'person' => $person,
        'user' => $user,
        'todo' => $todo,
        'ticket' => $ticket,
        'commentId' => $commentId,
        'hardware' => $hardware,
    ];
}

test('a full backup restores every table without a foreign key error', function () {
    seedBackupGraph();

    $archive = app(BackupService::class)->exportAll();
    $manifest = $this->backupZipJson($archive->path, 'manifest.json');

    $result = app(RestoreService::class)->restoreArchive($archive->path);

    expect($result->full)->toBeTrue();
    expect($result->tables)->toHaveCount(32);

    // Every table holds exactly what the file carried.
    foreach ($manifest['tables'] as $entry) {
        expect(DB::table($entry['table'])->count())->toBe($entry['rows'], "Table {$entry['table']} does not match the file.");
    }

    // The graph is back, in one piece.
    expect(DB::table('users')->where('id', 1001)->where('n_code', '1234567890')->count())->toBe(1);
    expect(DB::table('persons')->where('n_code', '1234567890')->count())->toBe(1);
    expect(DB::table('tickets')->where('id', 2001)->count())->toBe(1);
    expect(DB::table('ticket_comments')->where('ticket_id', 2001)->count())->toBe(2);
    expect(DB::table('ticket_comments')->whereNotNull('parent_id')->count())->toBe(1);
    expect(DB::table('user_units')->where('user_id', 1001)->count())->toBe(1);
    expect(DB::table('notifications')->where('user_id', 1001)->count())->toBe(1);
    expect(DB::table('hardware_audits')->where('user_id', 1001)->count())->toBe(1);
    expect(DB::table('daily_reports')->where('generated_by', 1001)->count())->toBe(1);
});

test('a full restore drops the rows that were written after the backup', function () {
    $graph = seedBackupGraph();

    $archive = app(BackupService::class)->exportAll();

    // A row that is NOT in the file — the restore has to remove it.
    $lateUnit = Unit::create(['name' => 'واحد دیرهنگام']);
    $latePerson = Person::factory()->create(['n_code' => '0987654321', 'u_id' => $lateUnit->id]);
    User::factory()->create(['id' => 1002, 'n_code' => $latePerson->n_code]);

    app(RestoreService::class)->restoreArchive($archive->path);

    expect(DB::table('units')->where('id', $lateUnit->id)->count())->toBe(0);
    expect(DB::table('persons')->where('n_code', '0987654321')->count())->toBe(0);
    expect(DB::table('users')->where('id', 1002)->count())->toBe(0);

    // ... and the rows that WERE in the file are still there.
    expect(DB::table('units')->where('id', $graph['childUnit']->id)->count())->toBe(1);
    expect(DB::table('users')->where('id', 1001)->count())->toBe(1);
});

test('an archive that carries only some tables is refused as a full backup', function () {
    seedBackupGraph();

    $archive = app(BackupService::class)->exportAll();
    $entries = $this->backupZipEntries($archive->path);

    // Two tables make the archive a FULL backup — but a full backup has to
    // carry all 32 of them. The name carries a random suffix: the backup
    // directory is shared by the parallel test workers, and a fixed name
    // would let one worker overwrite another one's archive.
    $partial = $this->backupMakeZip(
        storage_path('app/private/backup/partial-full-'.Str::random(8).'.zip'),
        [
            'persons.json' => $entries['persons.json'],
            'users.json' => $entries['users.json'],
            'manifest.json' => $entries['manifest.json'],
        ]
    );

    $before = DB::table('users')->count();

    expect(fn () => app(RestoreService::class)->restoreArchive($partial))
        ->toThrow(BackupValidationException::class);

    expect(DB::table('users')->count())->toBe($before);
});

test('a full backup of an empty database restores cleanly', function () {
    $archive = app(BackupService::class)->exportAll();

    $result = app(RestoreService::class)->restoreArchive($archive->path);

    expect($result->full)->toBeTrue();
    expect($result->rowCount)->toBe(0);

    foreach (app(TableRegistry::class)->tables() as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

test('a full restore leaves the sequences ready for the next create', function () {
    seedBackupGraph();

    $archive = app(BackupService::class)->exportAll();

    app(RestoreService::class)->restoreArchive($archive->path);

    $unit = Unit::create(['name' => 'واحد تازه']);

    expect($unit->id)->toBeGreaterThan(0);
    expect(DB::table('units')->where('id', $unit->id)->count())->toBe(1);
});
