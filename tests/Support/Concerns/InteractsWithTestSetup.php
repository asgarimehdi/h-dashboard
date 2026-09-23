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
     * Seed all four lookup tables using factories and resync Postgres sequences.
     * Call this in setUp() instead of raw DB::table()->insert() + setval().
     */
    protected function seedLookupTables(): void
    {
        // Use factories — they create realistic data and auto-handle sequences
        TahsilFactory::new()->count(1)->create();
        EstekhdamFactory::new()->count(1)->create();
        SematFactory::new()->count(1)->create();
        RadifFactory::new()->count(1)->create();

        // Resync sequences to avoid duplicate-key collisions
        $this->resyncSequence('tahsils');
        $this->resyncSequence('estekhdams');
        $this->resyncSequence('semats');
        $this->resyncSequence('radifs');
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
