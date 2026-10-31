<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\Support\Fakes\OrderedScopeAccessService;
use Tests\TestCase;

/**
 * Issue #1179 — a scope hash must depend on the SET of accessible unit ids,
 * never on the order they arrive in.
 *
 * `Unit::descendantIds()` runs a recursive CTE with no `ORDER BY`, and
 * `AccessService` caches that array exactly as it comes back — so two users
 * holding the same scope can receive the same ids in a different order. Every
 * cache key built straight from the array (`md5(implode(',', $ids))`,
 * `md5(json_encode($ids))`) then differs between them, the shared cache entry
 * is missed, and the same heavy query is paid for again under a second key.
 *
 * The scope is handed to the request through `OrderedScopeAccessService`, which
 * returns a caller-controlled order — that is the whole point: the two requests
 * below carry the SAME ids and differ ONLY in their order.
 */
class ScopeCacheKeyStabilityTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $writtenCacheKeys = [];

    /** @var array<int, string> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        Event::listen(KeyWritten::class, function (KeyWritten $event): void {
            $this->writtenCacheKeys[] = $event->key;
        });

        DB::listen(function ($query): void {
            $this->queries[] = $query->sql;
        });
    }

    /**
     * A parent unit with one child, so the scope holds more than a single id
     * and a reordering is actually observable.
     *
     * @param  array<string>  $permissions
     * @return array{user: User, parent: Unit, child: Unit}
     */
    private function seedScope(array $permissions): array
    {
        $result = $this->createUserWithUnit($permissions);
        $parent = $result['unit'];
        $child = Unit::factory()->create(['parent_id' => $parent->id]);

        return ['user' => $result['user'], 'parent' => $parent, 'child' => $child];
    }

    /**
     * Hand the request the given ids, in the given order.
     *
     * @param  array<int>  $ids
     */
    private function bindScope(array $ids): void
    {
        $accessService = new OrderedScopeAccessService;
        $accessService->ids = $ids;

        $this->app->instance(AccessService::class, $accessService);
    }

    /**
     * @return array<int, string>
     */
    private function cacheKeysWritten(string $prefix): array
    {
        return array_values(array_filter(
            $this->writtenCacheKeys,
            fn (string $key): bool => str_starts_with($key, $prefix)
        ));
    }

    private function countQueriesContaining(string $needle): int
    {
        return count(array_filter(
            $this->queries,
            fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    public function test_the_same_scope_in_a_different_order_reuses_one_cache_entry(): void
    {
        $scope = $this->seedScope(['view_hr_dashboard']);
        $token = $this->createApiToken($scope['user'], ['hr:read']);

        $this->bindScope([$scope['child']->id, $scope['parent']->id]);
        $first = $this->apiGet('/api/hr/stats', $token);
        $first->assertStatus(200);

        $statsQueriesAfterFirst = $this->countQueriesContaining('jsonb_object_agg');
        $this->assertGreaterThan(0, $statsQueriesAfterFirst, 'the first request must run the stats query');

        // The SAME ids — only the order they arrive in changes.
        $this->bindScope([$scope['parent']->id, $scope['child']->id]);
        $second = $this->apiGet('/api/hr/stats', $token);
        $second->assertStatus(200);

        $this->assertSame($first->json(), $second->json(), 'a reordered scope must not change the payload');
        $this->assertCount(
            1,
            $this->cacheKeysWritten('hr_stats:'),
            'a reordered scope must not open a second cache entry for the same scope'
        );
        $this->assertSame(
            $statsQueriesAfterFirst,
            $this->countQueriesContaining('jsonb_object_agg'),
            'the second request must be served from the cache'
        );
    }

    public function test_the_same_scope_in_a_different_order_reuses_one_hardware_stats_cache_entry(): void
    {
        $scope = $this->seedScope(['manage_hardware']);
        $token = $this->createApiToken($scope['user'], ['hardware:read']);

        $this->bindScope([$scope['child']->id, $scope['parent']->id]);
        $first = $this->apiGet('/api/hardware/stats', $token);
        $first->assertStatus(200);

        $hardwareQueriesAfterFirst = $this->countQueriesContaining('hardwares');
        $this->assertGreaterThan(0, $hardwareQueriesAfterFirst, 'the first request must run the hardware stats query');

        $this->bindScope([$scope['parent']->id, $scope['child']->id]);
        $second = $this->apiGet('/api/hardware/stats', $token);
        $second->assertStatus(200);

        $this->assertSame($first->json(), $second->json(), 'a reordered scope must not change the payload');
        $this->assertCount(
            1,
            $this->cacheKeysWritten('hardware_stats:'),
            'a reordered scope must not open a second cache entry for the same scope'
        );
        $this->assertSame(
            $hardwareQueriesAfterFirst,
            $this->countQueriesContaining('hardwares'),
            'the second request must be served from the cache'
        );
    }

    /**
     * The Done criterion of the issue, as a test: no controller may hash the
     * accessible ids by hand any more — the shared helper owns that.
     */
    public function test_no_controller_hashes_the_accessible_ids_by_hand(): void
    {
        $offenders = [];

        foreach (File::allFiles(base_path('app/Http/Controllers')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();

            foreach (["md5(implode(',', \$accessibleIds))", 'md5(json_encode($accessibleIds))'] as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = $file->getRelativePathname().' → '.$pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Scope hashes must go through App\Support\ScopeCacheKey::hash().'
        );
    }

    /**
     * `GisController::gisCacheKey()` is the documented exception: it hashes
     * with `strval` + `SORT_STRING`, and migrating it would change the GIS
     * cache keys. No OTHER controller may roll its own scope hash.
     */
    public function test_only_the_gis_controller_may_roll_its_own_scope_hash(): void
    {
        $offenders = [];

        foreach (File::allFiles(base_path('app/Http/Controllers/Api')) as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'GisController.php') {
                continue;
            }

            $contents = $file->getContents();

            if (str_contains($contents, 'md5(implode(') || str_contains($contents, 'md5(json_encode(')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Scope hashes must go through App\Support\ScopeCacheKey::hash().'
        );
    }
}
