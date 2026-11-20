<?php

use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

/**
 * An account whose numeric id is fixed, carrying one role and one API token.
 *
 * The id matters: the restore writes the ids of the file, and the grants of
 * the PRE-restore ids are the ones that must not survive (#1617).
 *
 * @return array{user: User, plainText: string}
 */
function makeRestoreTokenUser(int $id, string $nCode): array
{
    $user = User::factory()->create(['id' => $id, 'n_code' => $nCode]);
    $user->assignRole('admin');

    $token = $user->createToken('flutter-app', ['tickets:read']);

    return ['user' => $user, 'plainText' => $token->plainTextToken];
}

test('a per-table users restore revokes the token and the role of every pre-existing account', function () {
    makeRestoreTokenUser(931, '1111111111');
    makeRestoreTokenUser(932, '2222222222');

    // The factory wrote explicit ids; the next auto-increment must not reuse them.
    $this->resyncSequence('users');

    expect(DB::table('personal_access_tokens')->count())->toBe(2);
    expect(DB::table('model_has_roles')->where('model_type', User::class)->count())->toBe(2);

    $archive = app(BackupService::class)->exportTable('users');

    app(RestoreService::class)->restoreArchive($archive->path, 'users');

    // The accounts are back — the file carried them — but their grants are not.
    expect(DB::table('users')->where('id', 931)->count())->toBe(1);
    expect(DB::table('users')->where('id', 932)->count())->toBe(1);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    expect(DB::table('model_has_roles')->where('model_type', User::class)->count())->toBe(0);
});

test('a pre-restore token stops authenticating while a freshly minted one works', function () {
    $old = makeRestoreTokenUser(941, '3333333333');

    $this->resyncSequence('users');

    $archive = app(BackupService::class)->exportTable('users');

    app(RestoreService::class)->restoreArchive($archive->path, 'users');

    // The surviving id is not a credential: the old token is gone.
    $this->withHeader('Authorization', "Bearer {$old['plainText']}")
        ->getJson('/api/user')
        ->assertUnauthorized();

    $this->app['auth']->forgetGuards();

    // A token minted AFTER the restore is unaffected — the wipe is scoped to
    // the pre-restore ids, not to the whole table.
    $plainText = User::find(941)->createToken('after-restore', ['tickets:read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$plainText}")
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', 941);
});

test('a full restore revokes every pre-existing account token and role', function () {
    makeRestoreTokenUser(951, '4444444444');
    makeRestoreTokenUser(952, '5555555555');

    $this->resyncSequence('users');

    expect(DB::table('personal_access_tokens')->count())->toBe(2);

    $archive = app(BackupService::class)->exportAll();

    app(RestoreService::class)->restoreArchive($archive->path);

    // The file carried both accounts, so they exist again — with no grants.
    expect(DB::table('users')->whereIn('id', [951, 952])->count())->toBe(2);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    expect(DB::table('model_has_roles')->where('model_type', User::class)->count())->toBe(0);
    expect(DB::table('model_has_permissions')->where('model_type', User::class)->count())->toBe(0);
});
