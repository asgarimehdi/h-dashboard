<?php

namespace Tests\Support\Concerns;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Database\Factories\EstekhdamFactory;
use Database\Factories\RadifFactory;
use Database\Factories\SematFactory;
use Database\Factories\TahsilFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

trait InteractsWithTestSetup
{
    /**
     * Seed all four lookup tables with a deterministic row at id=1 and resync
     * Postgres sequences. Call this in setUp() instead of raw DB::table()->insert() + setval().
     *
     * Tests hardcode lookup FKs as 1 (t_id/e_id/s_id/r_id => 1). Postgres
     * sequences are NOT transactional: RefreshDatabase rolls back each test's
     * rows but the sequence keeps advancing, so an auto-incremented factory row
     * gets id=2,3,… on later tests and the hardcoded FKs break. Forcing id=1
     * restores the contract on every test.
     */
    protected function seedLookupTables(): void
    {
        $lookups = [
            'tahsils' => TahsilFactory::class,
            'estekhdams' => EstekhdamFactory::class,
            'semats' => SematFactory::class,
            'radifs' => RadifFactory::class,
        ];

        foreach ($lookups as $table => $factory) {
            if (! DB::table($table)->where('id', 1)->exists()) {
                $factory::new()->create(['id' => 1]);
            }

            // Resync sequence so later insertGetId() calls skip id=1.
            $this->resyncSequence($table);
        }
    }

    /**
     * Resync a Postgres sequence to MAX(id) to avoid duplicate-key errors
     * after manual inserts with explicit IDs.
     */
    protected function resyncSequence(string $table): void
    {
        DB::statement("SELECT setval('{$table}_id_seq', COALESCE((SELECT MAX(id) FROM {$table}), 1))");
    }

    /**
     * Create a user with a unit using factories.
     *
     * @param  array<string>  $permissions  Spatie permission names to grant
     * @param  string|null  $role  Spatie role name to assign (optional)
     * @return array{user: User, unit: Unit}
     */
    protected function createUserWithUnit(array $permissions = [], ?string $role = null): array
    {
        $unit = Unit::factory()->create();
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $user = User::factory()->create(['n_code' => $person->n_code]);

        if ($role) {
            $user->assignRole($role);
        }

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    protected function createHardware(array $data = []): Hardware
    {
        return Hardware::factory()->create($data);
    }

    protected function assertCacheInvalidated(string $cacheKey): void
    {
        $versionBefore = Cache::get($cacheKey.'_version', 0);
        $this->createHardware(['pc_name' => 'Cache-Invalidate-Test']);
        $versionAfter = Cache::get($cacheKey.'_version', 0);
        $this->assertGreaterThan($versionBefore, $versionAfter, "Cache key '{$cacheKey}' was not invalidated.");
    }

    protected function assertQueryCount(int $expected, Closure $callback): void
    {
        $queries = [];
        DB::listen(fn ($query) => $queries[] = $query->sql);
        $callback();
        $this->assertLessThanOrEqual($expected, count($queries),
            "Expected ≤ {$expected} queries, got ".count($queries));
    }

    protected function assertNoNPlusOne(Closure $callback, int $maxQueries = 5): void
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (! Str::startsWith($query->sql, ['BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT'])) {
                $count++;
            }
        });
        $callback();
        $this->assertLessThanOrEqual($maxQueries, $count, "N+1 detected: {$count} queries");
    }
}
