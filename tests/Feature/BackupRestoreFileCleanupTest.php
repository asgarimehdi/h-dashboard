<?php

use App\Console\Commands\PruneStaleCache;
use App\Exceptions\BackupValidationException;
use App\Models\Attachment;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1776 — a restore replaces rows with RAW deletes and inserts, so no
 * model hook fires: the attachment files of the rows the file drops were left
 * on disk forever, and the versioned caches kept serving pre-restore data.
 *
 * The restore must delete only the files the file no longer references (a
 * full restore re-inserts the rows it deletes, so deleting every file the
 * replaced rows pointed at would destroy the files the returning rows use)
 * and bump every registered cache namespace after the commit.
 */
uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seedLookupTables();
});

/**
 * The smallest graph the full restore needs: a unit type, a unit, a person,
 * the account bound to that person, and a ticket.
 *
 * The name is unique on purpose: Pest loads every test file into one process,
 * so a helper that collides with a global of another file is a fatal error.
 *
 * @return array{0: UnitType, 1: Unit, 2: Person, 3: User, 4: Ticket}
 */
function seedFileCleanupGraph(): array
{
    $unitType = UnitType::create(['name' => 'بیمارستان']);
    $unit = Unit::create(['name' => 'واحد پیوست', 'unit_type_id' => $unitType->id]);
    $person = Person::factory()->create(['n_code' => '1234567890', 'u_id' => $unit->id]);
    $user = User::factory()->create(['id' => 1001, 'n_code' => $person->n_code]);

    $ticket = Ticket::create([
        'ticket_code' => 'T-1776-'.Str::upper(Str::random(8)),
        'user_id' => $user->id,
        'unit_id' => $unit->id,
        'subject' => 'تیکت پیوست',
        'content' => 'متن',
        'priority' => 'normal',
        'status' => 'created',
    ]);

    return [$unitType, $unit, $person, $user, $ticket];
}

/**
 * Store a real file on the `local` disk and the row that points at it.
 */
function attachBackupCleanupFile(string $name, Ticket $ticket, User $user, ?int $activityId = null): Attachment
{
    $path = "attachments/{$name}";

    Storage::disk('local')->put($path, 'pdf-bytes');

    return Attachment::create([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'file_path' => $path,
        'file_name' => $name,
        'file_size' => 9,
        'activity_id' => $activityId,
    ]);
}

test('a full restore keeps the files its rows still reference and deletes the rest', function () {
    [, , , $user, $ticket] = seedFileCleanupGraph();

    attachBackupCleanupFile('keep.pdf', $ticket, $user);

    $archive = app(BackupService::class)->exportAll();

    // Written AFTER the backup: no row of the file references this file.
    attachBackupCleanupFile('drop.pdf', $ticket, $user);

    app(RestoreService::class)->restoreArchive($archive->path);

    // The row the file carries comes back — and so must its file.
    Storage::disk('local')->assertExists('attachments/keep.pdf');
    expect(DB::table('attachments')->where('file_path', 'attachments/keep.pdf')->count())->toBe(1);

    // The dropped row leaves no file behind.
    Storage::disk('local')->assertMissing('attachments/drop.pdf');
    expect(DB::table('attachments')->where('file_path', 'attachments/drop.pdf')->count())->toBe(0);
});

test('a full restore bumps every registered cache namespace once', function () {
    seedFileCleanupGraph();

    $archive = app(BackupService::class)->exportAll();

    $cache = app(CacheInvalidationServiceInterface::class);

    $before = [];
    foreach (PruneStaleCache::NAMESPACES as $namespace) {
        $before[$namespace] = $cache->getVersion($namespace);
    }

    app(RestoreService::class)->restoreArchive($archive->path);

    foreach (PruneStaleCache::NAMESPACES as $namespace) {
        expect($cache->getVersion($namespace))
            ->toBe($before[$namespace] + 1, "The «{$namespace}» namespace was not bumped exactly once.");
    }
});

test('a per-table tickets restore is refused and keeps every attachment file when a live attachment references a dropped ticket', function () {
    [, $unit, , $user, $ticket] = seedFileCleanupGraph();

    attachBackupCleanupFile('keep.pdf', $ticket, $user);

    $archive = app(BackupService::class)->exportTable('tickets');

    $dropped = Ticket::create([
        'ticket_code' => 'T-1776-DROP-'.Str::upper(Str::random(8)),
        'user_id' => $user->id,
        'unit_id' => $unit->id,
        'subject' => 'تیکت حذف‌شدنی',
        'content' => 'متن',
        'priority' => 'normal',
        'status' => 'created',
    ]);

    attachBackupCleanupFile('drop.pdf', $dropped, $user);

    // The live-child gate (owner decision Q1-(b)) refuses a parent restore
    // whose file would orphan a live child — BEFORE any write. A rejection
    // must not cost a file.
    expect(fn () => app(RestoreService::class)->restoreArchive($archive->path, 'tickets'))
        ->toThrow(BackupValidationException::class);

    Storage::disk('local')->assertExists('attachments/keep.pdf');
    Storage::disk('local')->assertExists('attachments/drop.pdf');

    expect(DB::table('tickets')->where('id', $dropped->id)->count())->toBe(1);
    expect(DB::table('attachments')->where('file_path', 'attachments/drop.pdf')->count())->toBe(1);
});

test('a per-table attachments restore deletes the files it drops', function () {
    [, , , $user, $ticket] = seedFileCleanupGraph();

    attachBackupCleanupFile('keep.pdf', $ticket, $user);

    $archive = app(BackupService::class)->exportTable('attachments');

    attachBackupCleanupFile('drop.pdf', $ticket, $user);

    app(RestoreService::class)->restoreArchive($archive->path, 'attachments');

    Storage::disk('local')->assertMissing('attachments/drop.pdf');
    Storage::disk('local')->assertExists('attachments/keep.pdf');

    expect(DB::table('attachments')->where('file_path', 'attachments/drop.pdf')->count())->toBe(0);
    expect(DB::table('attachments')->where('file_path', 'attachments/keep.pdf')->count())->toBe(1);
});

test('a per-table task_activities restore is refused and keeps the cascaded attachment file', function () {
    [, , , $user, $ticket] = seedFileCleanupGraph();

    $keptActivity = DB::table('task_activities')->insertGetId([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'action' => 'created',
        'description' => 'فعالیت ماندنی',
        'is_internal' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    attachBackupCleanupFile('keep.pdf', $ticket, $user, $keptActivity);

    $archive = app(BackupService::class)->exportTable('task_activities');

    $droppedActivity = DB::table('task_activities')->insertGetId([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'action' => 'forwarded',
        'description' => 'فعالیت حذف‌شدنی',
        'is_internal' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // `attachments.activity_id` is ON DELETE CASCADE, but the live-child gate
    // refuses this per-table parent restore before the cascade could run.
    attachBackupCleanupFile('drop.pdf', $ticket, $user, $droppedActivity);

    expect(fn () => app(RestoreService::class)->restoreArchive($archive->path, 'task_activities'))
        ->toThrow(BackupValidationException::class);

    Storage::disk('local')->assertExists('attachments/keep.pdf');
    Storage::disk('local')->assertExists('attachments/drop.pdf');

    expect(DB::table('task_activities')->where('id', $droppedActivity)->count())->toBe(1);
    expect(DB::table('attachments')->where('file_path', 'attachments/drop.pdf')->count())->toBe(1);
});

test('a rejected archive does not delete attachment files', function () {
    [, , , $user, $ticket] = seedFileCleanupGraph();

    attachBackupCleanupFile('keep.pdf', $ticket, $user);

    $archive = app(BackupService::class)->exportAll();
    $entries = $this->backupZipEntries($archive->path);

    $users = json_decode($entries['users.json'], true);
    $users[0]['n_code'] = '0000000000';
    $entries['users.json'] = json_encode($users);

    $broken = $this->backupMakeZip(
        storage_path('app/private/backup/full-rejected-'.Str::random(8).'.zip'),
        $entries
    );

    expect(fn () => app(RestoreService::class)->restoreArchive($broken))
        ->toThrow(BackupValidationException::class);

    // Validation runs before the first write, so the file has to survive.
    Storage::disk('local')->assertExists('attachments/keep.pdf');
});
