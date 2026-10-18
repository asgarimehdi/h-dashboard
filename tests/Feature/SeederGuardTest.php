<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UsersTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers([UsersTableSeeder::class, DatabaseSeeder::class]);

/**
 * #1036 — UsersTableSeeder ships three demo accounts sharing one trivial
 * literal password. The approved fix (option الف) is a production guard on
 * that seeder only, with the seeder detached from DatabaseSeeder's
 * reference-data batch so a production `migrate --seed` is never locked.
 */
class SeederGuardTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * The demo accounts UsersTableSeeder inserts, and that RoleSeeder grants
     * `admin` to through `User::whereIn('id', [1, 2, 3])`.
     */
    private const DEMO_N_CODES = ['4411015056', '4400176134', '4400176143'];

    /**
     * The three demo accounts only get ids 1..3 (and therefore their `admin`
     * role) when the users sequence starts at 1. Postgres sequences are not
     * transactional, so RefreshDatabase's rollback does not restart them.
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
            // Both the DB failure and the guard surface as RuntimeException;
            // assert the guard's own wording so this cannot pass on an FK/DB
            // error instead of a real refusal.
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

        $demoIds = DB::table('users')
            ->whereIn('n_code', self::DEMO_N_CODES)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // RoleSeeder grants `admin` by id (1..3). Detaching UsersTableSeeder
        // must not break that contract — the call has to stay *before*
        // RoleSeeder in DatabaseSeeder.
        $this->assertSame([1, 2, 3], $demoIds, 'حساب‌های دموی seeder باید شناسه‌های ۱ تا ۳ را بگیرند.');

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $this->assertNotNull($adminRoleId, 'RoleSeeder باید نقش admin را بسازد.');

        foreach ($demoIds as $userId) {
            $this->assertDatabaseHas('model_has_roles', [
                'role_id' => $adminRoleId,
                'model_type' => 'App\Models\User',
                'model_id' => $userId,
            ]);
        }
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

        foreach (self::DEMO_N_CODES as $nCode) {
            $this->assertDatabaseMissing('users', ['n_code' => $nCode]);
        }
    }
}
