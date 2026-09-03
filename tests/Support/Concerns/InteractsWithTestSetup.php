<?php

namespace Tests\Support\Concerns;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait InteractsWithTestSetup
{
    protected function createUserWithUnit(array $unitData = [], array $permissions = []): array
    {
        $unit = Unit::factory()->create($unitData);
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $user = User::factory()->create(['n_code' => $person->n_code]);
        $user->givePermissionTo($permissions);

        return [$user, $unit];
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
