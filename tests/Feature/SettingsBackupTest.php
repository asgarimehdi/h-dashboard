<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Support\Backup\TableRegistry;
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

    $this->admin = User::factory()->create(['password' => Hash::make('password')]);
    $this->admin->assignRole('admin');
});

test('the backup section is hidden from a non admin', function () {
    $this->actingAs($this->user);

    Livewire::test('settings.index')
        ->assertDontSee('پشتیبان‌گیری و بازگردانی جداول');
});

test('the backup section lists every registered table for an admin', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('settings.index')
        ->assertSee('پشتیبان‌گیری و بازگردانی جداول');

    foreach (app(TableRegistry::class)->tables() as $table) {
        $component->assertSee($table);
    }
});

test('a non admin is refused the table download', function () {
    $this->actingAs($this->user)
        ->get(route('settings.backup.table', 'users'))
        ->assertForbidden();
});

test('a non admin is refused the full download', function () {
    $this->actingAs($this->user)
        ->get(route('settings.backup.full'))
        ->assertForbidden();
});

test('a guest is refused the table download', function () {
    $this->get(route('settings.backup.table', 'users'))
        ->assertRedirect('/login');
});

test('an admin downloads the table backup as a zip', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('settings.backup.table', 'users'));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('backup-users-');
});

test('an admin downloads the full backup as a zip', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('settings.backup.full'));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('backup-full-');
});

test('an unknown table is not downloadable', function () {
    $this->actingAs($this->admin)
        ->get(route('settings.backup.table', 'cache'))
        ->assertNotFound();
});

test('the download button is a plain link and not a livewire action', function () {
    $this->actingAs($this->admin);

    $html = Livewire::test('settings.index')->html();

    expect($html)->toContain(route('settings.backup.table', 'users'));
    expect($html)->not->toContain('wire:click="backupTable');
});

test('a non admin cannot run the restore action', function () {
    $archive = app(BackupService::class)->exportTable('users');

    $file = UploadedFile::fake()->createWithContent(
        'backup-users.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($this->user)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'users')
        ->call('restoreBackup')
        ->assertForbidden();
});

test('an admin restores a table from an uploaded zip', function () {
    $archive = app(BackupService::class)->exportTable('users');
    $rows = $this->backupZipJson($archive->path, 'users.json');

    $file = UploadedFile::fake()->createWithContent(
        'backup-users.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($this->admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'users')
        ->call('restoreBackup')
        ->assertHasNoErrors();

    expect(DB::table('users')->count())->toBe(count($rows));
});

test('a broken zip fails with a persian error and changes nothing', function () {
    $before = DB::table('users')->count();

    $file = UploadedFile::fake()->createWithContent('broken.zip', 'this is not a zip');

    Livewire::actingAs($this->admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'users')
        ->call('restoreBackup')
        ->assertHasNoErrors();

    expect(DB::table('users')->count())->toBe($before);
});

test('the warning about the users table is shown when users is the target', function () {
    $this->actingAs($this->admin);

    Livewire::test('settings.index')
        ->set('restoreTarget', 'users')
        ->assertSee('نقش‌ها و توکن‌های کاربران بازگردانی نمی‌شوند');

    Livewire::test('settings.index')
        ->set('restoreTarget', 'tickets')
        ->assertDontSee('نقش‌ها و توکن‌های کاربران بازگردانی نمی‌شوند');
});

test('the users restore only runs after the warning was confirmed', function () {
    $archive = app(BackupService::class)->exportTable('users');

    $file = UploadedFile::fake()->createWithContent(
        'backup-users.zip',
        (string) file_get_contents($archive->path)
    );

    Livewire::actingAs($this->admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'users')
        ->call('restoreBackup')
        ->assertSet('restoreConfirmed', false);

    Livewire::actingAs($this->admin)
        ->test('settings.index')
        ->set('backupFile', $file)
        ->set('restoreTarget', 'users')
        ->set('restoreConfirmed', true)
        ->call('restoreBackup')
        ->assertHasNoErrors();
});
