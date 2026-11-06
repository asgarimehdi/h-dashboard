<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\Backup\BackupService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست', 'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    $this->user = User::factory()->create(['password' => Hash::make('password')]);
});

/**
 * An admin account — created on demand, because one test has to export the
 * backup BEFORE the admin exists.
 */
function makeBackupAdmin(): User
{
    $admin = User::factory()->create(['password' => Hash::make('password')]);
    $admin->assignRole('admin');

    return $admin;
}

test('a backup is recorded in the activity log', function () {
    $admin = makeBackupAdmin();

    $this->actingAs($admin)
        ->get(route('settings.backup.table', 'users'))
        ->assertOk();

    $log = DB::table('activity_logs')->where('type', 'backup')->first();

    expect($log)->not->toBeNull();
    expect($log->description)->toContain('users');
    expect($log->user_id)->toBe($admin->id);
});

test('a full backup is recorded in the activity log', function () {
    $admin = makeBackupAdmin();

    $this->actingAs($admin)
        ->get(route('settings.backup.full'))
        ->assertOk();

    $log = DB::table('activity_logs')->where('type', 'backup')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($admin->id);
});

test('a restore is recorded in the activity log', function () {
    $admin = makeBackupAdmin();

    $archive = app(BackupService::class)->exportTable('users');

    $file = UploadedFile::fake()->createWithContent(
        'backup-users.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'users')
        ->set('restoreConfirmed', true)
        ->call('restoreBackup')
        ->assertHasNoErrors();

    $log = DB::table('activity_logs')->where('type', 'restore')->first();

    expect($log)->not->toBeNull();
    expect($log->description)->toContain('users');
    expect($log->user_id)->toBe($admin->id);
});

test('the restore log is written after the tables were replaced', function () {
    $admin = makeBackupAdmin();

    // The archive is taken BEFORE the backup log exists, so the file carries
    // an empty `activity_logs` table.
    $archive = app(BackupService::class)->exportAll();

    // The backup writes a log — a full restore replaces `activity_logs`, so
    // this row is the one that proves the ORDER.
    $this->actingAs($admin)
        ->get(route('settings.backup.full'))
        ->assertOk();

    expect(DB::table('activity_logs')->count())->toBe(1);

    $file = UploadedFile::fake()->createWithContent(
        'backup-full.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'full')
        ->set('restoreConfirmed', true)
        ->call('restoreBackup')
        ->assertHasNoErrors();

    // The backup log was wiped by the replacement; only the restore log —
    // written AFTER the replacement — is left.
    $logs = DB::table('activity_logs')->get();

    expect($logs)->toHaveCount(1);
    expect($logs[0]->type)->toBe('restore');
});

test('the restore log has no actor when the admin account was replaced', function () {
    // The archive is taken BEFORE the admin exists, so the restored `users`
    // table does NOT contain the acting admin.
    $archive = app(BackupService::class)->exportAll();

    $admin = makeBackupAdmin();

    $file = UploadedFile::fake()->createWithContent(
        'backup-full.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'full')
        ->set('restoreConfirmed', true)
        ->call('restoreBackup')
        ->assertHasNoErrors();

    // The admin account is gone ...
    expect(DB::table('users')->where('id', $admin->id)->count())->toBe(0);

    // ... so the log is written WITHOUT an actor: an insert that carried the
    // old id would violate the foreign key of `activity_logs.user_id`.
    $log = DB::table('activity_logs')->where('type', 'restore')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBeNull();
});

test('the restore log keeps the actor when the admin account survived', function () {
    $admin = makeBackupAdmin();

    $archive = app(BackupService::class)->exportAll();

    $file = UploadedFile::fake()->createWithContent(
        'backup-full.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'full')
        ->set('restoreConfirmed', true)
        ->call('restoreBackup')
        ->assertHasNoErrors();

    expect(DB::table('users')->where('id', $admin->id)->count())->toBe(1);

    $log = DB::table('activity_logs')->where('type', 'restore')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($admin->id);
});
