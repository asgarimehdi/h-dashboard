<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccessService;
        Cache::flush();
    }

    public function single_unit_user_gets_only_that_unit(): void
    {
        $unit = Unit::factory()->create();
        $user = User::factory()->create();
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        $ids = $this->service->accessibleUnitIds($user);

        $this->assertCount(1, $ids);
        $this->assertContains($unit->id, $ids);
    }

    public function user_with_children_gets_all_descendants(): void
    {
        $parent = Unit::factory()->create();
        $child1 = Unit::factory()->create(['parent_id' => $parent->id]);
        $child2 = Unit::factory()->create(['parent_id' => $parent->id]);
        $user = User::factory()->create();
        $user->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);

        $ids = $this->service->accessibleUnitIds($user);

        $this->assertCount(3, $ids);
        $this->assertContains($parent->id, $ids);
        $this->assertContains($child1->id, $ids);
        $this->assertContains($child2->id, $ids);
    }

    public function multi_unit_user_respects_current_context(): void
    {
        $unitA = Unit::factory()->create(['name' => 'Unit A']);
        $unitB = Unit::factory()->create(['name' => 'Unit B']);
        $childOfB = Unit::factory()->create(['parent_id' => $unitB->id]);
        $user = User::factory()->create();

        $user->units()->attach($unitA->id, ['role' => 'staff', 'is_primary' => true]);
        $user->units()->attach($unitB->id, ['role' => 'staff', 'is_primary' => false]);

        // Without session set: returns both units + descendants
        $idsWithoutSession = $this->service->accessibleUnitIds($user);
        $this->assertContains($unitA->id, $idsWithoutSession);
        $this->assertContains($unitB->id, $idsWithoutSession);
        $this->assertContains($childOfB->id, $idsWithoutSession);

        // With session set to unitA (no children): only unitA
        Session::put('current_unit_id', $unitA->id);
        $idsWithSessionA = $this->service->accessibleUnitIds($user);
        $this->assertCount(1, $idsWithSessionA);
        $this->assertContains($unitA->id, $idsWithSessionA);
        $this->assertNotContains($unitB->id, $idsWithSessionA);

        // With session set to unitB (has children): unitB + its children
        Session::put('current_unit_id', $unitB->id);
        $idsWithSessionB = $this->service->accessibleUnitIds($user);
        $this->assertCount(2, $idsWithSessionB);
        $this->assertContains($unitB->id, $idsWithSessionB);
        $this->assertContains($childOfB->id, $idsWithSessionB);
        $this->assertNotContains($unitA->id, $idsWithSessionB);
    }

    public function clear_cache_removes_user_cache(): void
    {
        $unit = Unit::factory()->create();
        $child = Unit::factory()->create(['parent_id' => $unit->id]);
        $user = User::factory()->create();
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        // Prime the cache
        $firstCall = $this->service->accessibleUnitIds($user);
        $this->assertCount(2, $firstCall);

        // Verify cache key exists
        $currentUnitId = session('current_unit_id', 'none');
        $baseUnitIds = [$unit->id];
        $cacheKey = "accessible_units:{$user->id}:{$currentUnitId}:".md5(json_encode($baseUnitIds));
        $this->assertTrue(Cache::has($cacheKey));

        // Clear cache
        $this->service->clearCache($user);

        // Verify cache key is gone
        $this->assertFalse(Cache::has($cacheKey));
    }
}
