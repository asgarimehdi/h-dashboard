<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UsersTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers([UsersTableSeeder::class, RoleSeeder::class, DatabaseSeeder::class]);

/**
 * #1036 — UsersTableSeeder ships three demo accounts sharing one trivial
 * literal password. The approved fix (option الف) is a production guard on
 * that seeder only, with the seeder detached from DatabaseSeeder's
 * reference-data batch so a production `migrate --seed` is never locked.
 *
 * #1847 — RoleSeeder grants `admin` to the demo accounts by their `n_code`
 * identity (and only where UsersTableSeeder is allowed to create them), never
 * by bare id: on a database that already has users, ids 1..3 are real staff.
 */
class SeederGuardTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * Postgres sequences are not transactional, so RefreshDatabase's rollback
     * does not restart them. Resets every public sequence, because which
     * fixtures a randomly ordered suite has already advanced is unknowable.
     * RegionSeeder hardcodes boundary ids 1..9, so DatabaseSeeder only seeds
     * cleanly when the boundaries sequence starts at 1; a test that runs
     * DatabaseSeeder to the end also relies on its resetPostgresSequences()
     * putting every sequence back at MAX(id) + 1.
     */
    private function restartSequences(): void
    {
        $sequences = DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c
            WHERE c.relkind = 'S'
              AND c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
        SQL);

        foreach ($sequences as $sequence) {
            DB::statement("SELECT setval('\"{$sequence->name}\"', 1, false)");
        }
    }

    private function fakeProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_users_table_seeder_refuses_to_run_in_production(): void
    {
        $this->fakeProduction();

        try {
            $this->artisan('db:seed', [
                '--class' => UsersTableSeeder::class,
                '--force' => true,
            ])->run();

            $this->fail('UsersTableSeeder باید در محیط production اجرا را رد کند.');
        } catch (RuntimeException $e) {
            // PHPUnit's own AssertionFailedError also extends RuntimeException,
            // so pin the class first: otherwise the fail() above would be caught
            // here too and reported as a confusing message mismatch.
            $this->assertSame(RuntimeException::class, $e::class);

            // A DB failure (e.g. the users.n_code FK) is a RuntimeException too;
            // assert the guard's own wording so this cannot pass on one.
            $this->assertStringContainsString(
                'UsersTableSeeder is disabled in production',
                $e->getMessage(),
                'پیام گارد باید صریح باشد'
            );
        }

        // در production هیچ حساب دمویی نباید ساخته شود.
        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_seeder_keeps_the_admin_role_contract_outside_production(): void
    {
        $this->restartSequences();

        $this->seed(DatabaseSeeder::class);

        // RoleSeeder grants `admin` by n_code identity (#1847). The call has to
        // stay *before* RoleSeeder in DatabaseSeeder so the demo accounts exist
        // by the time the grant runs; this test pins that order indirectly.
        $adminNCodes = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('roles.name', 'admin')
            ->where('model_has_roles.model_type', 'App\Models\User')
            ->pluck('users.n_code')
            ->map(fn ($nCode) => (string) $nCode)
            ->sort()
            ->values()
            ->all();

        $expected = UsersTableSeeder::DEMO_N_CODES;
        sort($expected);

        $this->assertSame($expected, $adminNCodes, 'فقط سه اکانت دموی seeder باید نقش admin بگیرند.');

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $this->assertNotNull($adminRoleId, 'RoleSeeder باید نقش admin را بسازد.');

        foreach (UsersTableSeeder::DEMO_N_CODES as $nCode) {
            $userId = DB::table('users')->where('n_code', $nCode)->value('id');

            $this->assertNotNull($userId, "اکانت دموی {$nCode} باید ساخته شود.");
            $this->assertDatabaseHas('model_has_roles', [
                'role_id' => $adminRoleId,
                'model_type' => 'App\Models\User',
                'model_id' => $userId,
            ]);
        }
    }

    public function test_role_seeder_never_grants_admin_by_bare_id(): void
    {
        // A non-demo account that owns id 1 — exactly the account the old
        // `whereIn('id', [1, 2, 3])` grant promoted silently (#1847).
        $outsider = User::factory()->create([
            'id' => 1,
            'n_code' => '9900000001',
        ]);

        $demoUsers = [];
        foreach (array_values(UsersTableSeeder::DEMO_N_CODES) as $index => $nCode) {
            $demoUsers[] = User::factory()->create([
                'id' => $index + 2,
                'n_code' => $nCode,
            ]);
        }

        $this->resyncSequence('users');

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(
            $outsider->fresh()->hasRole('admin'),
            'کاربر غیردموی با id=1 نباید با id خام ادمین شود.'
        );

        foreach ($demoUsers as $demoUser) {
            $this->assertTrue(
                $demoUser->fresh()->hasRole('admin'),
                "اکانت دموی {$demoUser->n_code} باید ادمین شود."
            );
        }
    }

    public function test_role_seeder_does_not_grant_demo_admin_in_production(): void
    {
        // Permissions must exist for RoleSeeder's syncPermissions() calls; seed
        // them while still outside production.
        $this->seed(PermissionSeeder::class);

        $this->fakeProduction();

        $demoUsers = [];
        foreach (array_values(UsersTableSeeder::DEMO_N_CODES) as $index => $nCode) {
            // Pinned ids make this a deterministic witness: on the pre-#1847
            // code the bare-id grant promotes exactly these three accounts.
            $demoUsers[] = User::factory()->create([
                'id' => $index + 1,
                'n_code' => $nCode,
            ]);
        }

        $this->resyncSequence('users');

        // --force: db:seed refuses to run in production without it.
        $this->artisan('db:seed', [
            '--class' => RoleSeeder::class,
            '--force' => true,
        ])->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($demoUsers as $demoUser) {
            $this->assertFalse(
                $demoUser->fresh()->hasRole('admin'),
                'در production اکانت دمو نباید ادمین شود (UsersTableSeeder مجاز نیست).'
            );
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $this->assertNotNull($adminRoleId, 'RoleSeeder باید نقش admin را بسازد.');
        $this->assertSame(
            0,
            DB::table('model_has_roles')->where('role_id', $adminRoleId)->count(),
            'نقش admin در production باید بدون دارنده ساخته شود.'
        );
    }

    public function test_database_seeder_does_not_lock_reference_data_in_production(): void
    {
        $this->fakeProduction();
        $this->restartSequences();

        $this->artisan('db:seed', ['--force' => true])->run();

        // UsersTableSeeder is detached from the reference-data batch, so its
        // production refusal must not abort the batch: seeders placed after it
        // (RoleSeeder, UnitSeeder, ...) still run.
        $this->assertGreaterThan(0, DB::table('roles')->count(), 'RoleSeeder بعد از جایگاه seeder دمویی باید اجرا شود.');
        $this->assertGreaterThan(0, DB::table('units')->count(), 'دادهٔ مرجع واحدها باید در production seed شود.');

        foreach (UsersTableSeeder::DEMO_N_CODES as $nCode) {
            $this->assertDatabaseMissing('users', ['n_code' => $nCode]);
        }
    }
}
