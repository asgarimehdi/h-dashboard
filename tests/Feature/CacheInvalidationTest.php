<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(\Tests\Support\Concerns\InteractsWithTestSetup::class);

it('increments cache version on hardware create', function () {
    $this->assertCacheInvalidated('hardware_stats');
    $this->createHardware(['pc_name' => 'Test']);
});

it('invalidates GIS cache on hardware import', function () {
    Cache::set('gis_version', 1);
    \App\Models\Hardware::flushStatsCache();
    expect(Cache::get('gis_version'))->toBeGreaterThan(1);
});

it('detects N+1 queries in hardware list', function () {
    // Build the user outside the measured closure: factory setup issues its
    // own queries (unit/lookup seeds, person, user) which must not count toward
    // the N+1 assertion for the hardware list endpoint itself.
    $user = \App\Models\User::factory()->create();

    // Expected: 1) accessible-units lookup, 2) hardware list (paginated count +
    // data), 3) eager-loaded person/unit. No per-row query => no N+1.
    $this->assertNoNPlusOne(function () use ($user) {
        $this->actingAs($user)->getJson('/api/hardware');
    }, 4);
});