<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #984 (part 2) — the fix is per-request memoization, NOT a singleton.
 *
 * The original issue text prescribed binding `AccessService` as a container
 * singleton. On its own that is a no-op: the class is stateless and has no
 * constructor, so reusing the instance saves nothing by itself. The real cost is
 * repetition — `dashboard.blade.php` resolves the access array in `mount()`
 * (:71) and again in every scope-key accessor the view evaluates
 * (`ticketChartData` :239, `ticketStatusData` :265, `recentActivities` :282),
 * and each resolution pays `unit_hierarchy_version`, the `accessible_units:...`
 * remember, plus the same two keys again inside `Unit::descendantIds()` on a
 * miss.
 *
 * The tests therefore count CACHE READS, one `accessible_units:` read per real
 * resolution, instead of asserting instance identity — an identity assertion
 * passes against a singleton binding that changes nothing (that is the trap the
 * issue itself fell into).
 *
 * The memo key is `{user id}:{session unit id}:{unit_hierarchy version}`, i.e.
 * exactly the inputs the underlying cache key is built from. Two consequences
 * are pinned below: it must not leak between two users or across a context
 * switch, and it must never outlive a hierarchy write performed inside the same
 * request — `Unit::created/updated/deleted` bump `unit_hierarchy` from
 * `AppServiceProvider` (the same hook `HardwareGisCacheTest` relies on), and the
 * version in the key is what makes the next call see the new tree without every
 * bump site having to remember the memo.
 */
class AccessServiceMemoizationTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $cacheReads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        config(['cache.default' => 'array']);
    }

    /**
     * Record every cache key read from now on.
     */
    private function recordCacheReads(): void
    {
        Event::listen(RetrievingKey::class, function (RetrievingKey $event): void {
            $this->cacheReads[] = $event->key;
        });
    }

    /**
     * Number of real resolutions of the access array: one `accessible_units:...`
     * read each, zero for a memo hit.
     */
    private function resolutions(): int
    {
        return $this->countReads('accessible_units:');
    }

    /**
     * Every cache round-trip the access path can make:
     * `unit_hierarchy_version`, `accessible_units:...`, `unit_descendants:...`.
     */
    private function accessCacheReads(): int
    {
        return $this->countReads('unit_hierarchy_version')
            + $this->countReads('accessible_units:')
            + $this->countReads('unit_descendants:');
    }

    private function countReads(string $prefix): int
    {
        return count(array_filter(
            $this->cacheReads,
            fn (string $key): bool => str_starts_with($key, $prefix)
        ));
    }

    private function report(string $scenario): string
    {
        return "{$scenario}: resolutions={$this->resolutions()}, "
            ."access cache reads={$this->accessCacheReads()} "
            ."(versions={$this->countReads('unit_hierarchy_version')})";
    }

    public function test_repeated_resolutions_in_one_request_resolve_the_access_array_once(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);

        $this->recordCacheReads();

        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids = app(AccessService::class)->accessibleUnitIds();
        }

        $this->assertSame(1, $this->resolutions(), $this->report('5 resolutions in one request'));
        // 4 on the first (resolving) call, then one `unit_hierarchy_version`
        // read per call to prove the memo is still current — the
        // `accessible_units:` and `unit_descendants:` reads are gone.
        $this->assertSame(8, $this->accessCacheReads(), $this->report('5 resolutions in one request'));
        $this->assertSame([$result['unit']->id], $ids);
    }

    /**
     * The memo lives on the instance, so the instance must be shared for the
     * request. Necessary but not sufficient — see the count assertions.
     */
    public function test_two_resolutions_in_one_request_share_the_instance(): void
    {
        $this->assertSame(app(AccessService::class), app(AccessService::class));
    }

    public function test_one_dashboard_render_resolves_the_access_array_once(): void
    {
        $result = $this->createUserWithUnit(['map', 'calendar', 'organization']);
        $this->actingAs($result['user']);

        $this->recordCacheReads();

        $this->get('/dashboard')->assertStatus(200);

        // `mount()` + the scope-key accessors the view evaluates used to resolve
        // the array on every call: 7 identical arrays, 2 cache lookups each
        // (14 reads) plus 2 more for the first `Unit::descendantIds()` miss.
        // Now: one resolution (4 reads) and one version read per later call.
        $this->assertSame(1, $this->resolutions(), $this->report('one /dashboard render'));
        $this->assertSame(10, $this->accessCacheReads(), $this->report('one /dashboard render'));
    }

    /**
     * Without a session unit, every resolution first asks the database for the
     * caller's own units. Repeated resolutions used to repeat that query.
     */
    public function test_repeated_resolutions_without_a_session_unit_run_one_lookup_query(): void
    {
        $result = $this->createUserWithUnit();
        Session::forget('current_unit_id');
        $this->actingAs($result['user']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        for ($i = 0; $i < 5; $i++) {
            app(AccessService::class)->accessibleUnitIds();
        }

        $lookups = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'user_units'));

        $this->assertCount(1, $lookups, 'the own-units lookup must run once per request');
    }

    public function test_memo_does_not_leak_between_users_in_one_request(): void
    {
        $first = $this->createUserWithUnit();
        $second = $this->createUserWithUnit();

        // No session unit: each user's base units come from their own units, so
        // the two users must not share an answer.
        Session::forget('current_unit_id');

        $service = app(AccessService::class);

        $this->recordCacheReads();

        $firstIds = $service->accessibleUnitIds($first['user']);
        $secondIds = $service->accessibleUnitIds($second['user']);

        $this->assertSame([$first['unit']->id], $firstIds);
        $this->assertSame([$second['unit']->id], $secondIds);
        $this->assertSame(2, $this->resolutions(), $this->report('two users'));
    }

    public function test_memo_does_not_leak_across_a_context_switch_in_one_request(): void
    {
        $result = $this->createUserWithUnit();
        $otherUnit = Unit::create(['name' => 'واحد دوم']);
        $result['user']->units()->attach($otherUnit->id, ['role' => 'staff', 'is_primary' => false]);

        $service = app(AccessService::class);

        $this->recordCacheReads();

        Session::put('current_unit_id', $result['unit']->id);
        $beforeSwitch = $service->accessibleUnitIds($result['user']);

        Session::put('current_unit_id', $otherUnit->id);
        $afterSwitch = $service->accessibleUnitIds($result['user']);

        $this->assertSame([$result['unit']->id], $beforeSwitch);
        $this->assertSame([$otherUnit->id], $afterSwitch);
        $this->assertSame(2, $this->resolutions(), $this->report('context switch'));
    }

    /**
     * The regression `HardwareGisCacheTest` caught: a hierarchy write inside one
     * request bumps `unit_hierarchy` through the model hook in
     * `AppServiceProvider`, with no `clearCache()` call in sight. The memo must
     * not keep answering with the pre-write array afterwards.
     */
    public function test_hierarchy_write_in_one_request_is_visible_to_the_next_call(): void
    {
        $parent = Unit::create(['name' => 'Parent']);
        $result = $this->createUserWithUnit();
        $result['user']->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $parent->id);
        $this->actingAs($result['user']);

        $service = app(AccessService::class);

        $beforeWrite = $service->accessibleUnitIds($result['user']);
        $this->assertSame([$parent->id], $beforeWrite);

        // Stored through Eloquent, exactly like `HardwareGisCacheTest` does:
        // the `Unit::created` hook bumps the version, nothing calls clearCache().
        $newChild = Unit::create(['name' => 'Child', 'parent_id' => $parent->id]);

        $this->recordCacheReads();
        $afterWrite = $service->accessibleUnitIds($result['user']);

        $this->assertContains($newChild->id, $afterWrite, 'the memo must not hide an in-request hierarchy write');
        $this->assertSame(1, $this->resolutions(), $this->report('in-request Unit::create'));
    }

    public function test_clear_cache_drops_the_memoized_array(): void
    {
        $parent = Unit::create(['name' => 'Parent']);
        $child = Unit::create(['name' => 'Child 1', 'parent_id' => $parent->id]);
        $result = $this->createUserWithUnit();
        $result['user']->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $parent->id);
        $this->actingAs($result['user']);

        $service = app(AccessService::class);

        $this->recordCacheReads();

        $beforeWrite = $service->accessibleUnitIds($result['user']);
        $this->assertContains($child->id, $beforeWrite);

        // A hierarchy write inside the same request, plus the invalidation the
        // controller performs for it.
        $newChild = Unit::create(['name' => 'Child 2', 'parent_id' => $parent->id]);
        $service->clearCache($result['user']);

        $afterWrite = $service->accessibleUnitIds($result['user']);

        $this->assertContains($newChild->id, $afterWrite, 'clearCache() must drop the request-level memo');
        $this->assertCount(3, $afterWrite);
        $this->assertSame(2, $this->resolutions(), $this->report('clearCache'));
    }

    public function test_clear_all_caches_drops_the_memoized_array(): void
    {
        $parent = Unit::create(['name' => 'Parent']);
        $result = $this->createUserWithUnit();
        $result['user']->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $parent->id);
        $this->actingAs($result['user']);

        $service = app(AccessService::class);

        $this->recordCacheReads();

        $beforeWrite = $service->accessibleUnitIds($result['user']);

        $newChild = Unit::create(['name' => 'Child', 'parent_id' => $parent->id]);
        $service->clearAllCaches();

        $afterWrite = $service->accessibleUnitIds($result['user']);

        $this->assertNotContains($newChild->id, $beforeWrite);
        $this->assertContains($newChild->id, $afterWrite, 'clearAllCaches() must drop the request-level memo');
        $this->assertSame(2, $this->resolutions(), $this->report('clearAllCaches'));
    }
}
