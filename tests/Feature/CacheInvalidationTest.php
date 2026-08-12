<?php

namespace Tests\Feature;

use App\Services\CacheInvalidationService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(\Tests\Support\Concerns\InteractsWithTestSetup::class);

it('increments cache version on hardware create', function () {
    $this->assertCacheInvalidated('hardware_stats');
    $this->createHardware(['pc_name' => 'Test']);
});

it('invalidates GIS cache on hardware import', function () {
    Cache::set('gis_version', 1);
    app(CacheInvalidationService::class)->flushStatsCache();
    expect(Cache::get('gis_version'))->toBeGreaterThan(1);
});

it('detects N+1 queries in hardware list', function () {
    $this->assertNoNPlusOne(function () {
        $this->actingAs(User::factory()->create())->getJson('/api/hardware');
    }, 3);
});