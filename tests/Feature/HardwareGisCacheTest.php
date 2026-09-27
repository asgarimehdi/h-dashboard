<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(CacheInvalidationService::class);

uses(InteractsWithTestSetup::class);
uses(TestCase::class, RefreshDatabase::class);

it('does not double-increment gis on hardware create', function () {
    // Create hardware (factory creates Unit + Person + Hardware, each bumping gis)
    $hardware = $this->createHardware(['pc_name' => 'GIS-Dedup-Test']);

    // Reset AFTER all factory setup
    Cache::put('gis_version', 0);

    // Now update the hardware — should bump gis exactly once
    $hardware->update(['pc_name' => 'GIS-Dedup-After']);

    $this->assertEquals(1, Cache::get('gis_version'));
});

it('does not double-increment gis on hardware update', function () {
    $hardware = $this->createHardware(['pc_name' => 'GIS-Update-Before']);
    Cache::put('gis_version', 0);

    $hardware->update(['pc_name' => 'GIS-Update-After']);

    $this->assertEquals(1, Cache::get('gis_version'));
});

it('does not double-increment gis on hardware delete', function () {
    $hardware = $this->createHardware(['pc_name' => 'GIS-Delete-Test']);
    Cache::put('gis_version', 0);

    $hardware->delete();

    $this->assertEquals(1, Cache::get('gis_version'));
});

it('person save without u_id change only bumps hr_stats and dashboard', function () {
    $user = User::factory()->create();
    $person = Person::where('n_code', $user->n_code)->firstOrFail();

    // Set cache AFTER person creation (creation bumps maps/gis)
    Cache::put('hr_stats_version', 0);
    Cache::put('dashboard_version', 0);
    Cache::put('maps_version', 0);
    Cache::put('gis_version', 0);

    // Update only name — no u_id change
    $person->update(['f_name' => 'Updated Name']);

    $this->assertGreaterThan(0, Cache::get('hr_stats_version'), 'hr_stats should be bumped');
    $this->assertGreaterThan(0, Cache::get('dashboard_version'), 'dashboard should be bumped');
    $this->assertEquals(0, Cache::get('maps_version'), 'maps should NOT be bumped without u_id change');
    $this->assertEquals(0, Cache::get('gis_version'), 'gis should NOT be bumped without u_id change');
});

it('person save with u_id change bumps all four namespaces', function () {
    $user = User::factory()->create();
    $person = Person::where('n_code', $user->n_code)->firstOrFail();
    $oldUnitId = $person->u_id;

    // Find or create a different unit
    $newUnit = Unit::factory()->create();
    if ($newUnit->id === $oldUnitId) {
        $newUnit = Unit::factory()->create();
    }

    // Set cache AFTER person creation
    Cache::put('hr_stats_version', 0);
    Cache::put('dashboard_version', 0);
    Cache::put('maps_version', 0);
    Cache::put('gis_version', 0);

    $person->update(['u_id' => $newUnit->id]);

    $this->assertGreaterThan(0, Cache::get('hr_stats_version'));
    $this->assertGreaterThan(0, Cache::get('dashboard_version'));
    $this->assertGreaterThan(0, Cache::get('maps_version'), 'maps should be bumped when u_id changes');
    $this->assertGreaterThan(0, Cache::get('gis_version'), 'gis should be bumped when u_id changes');
});

it('person delete bumps all four namespaces', function () {
    $user = User::factory()->create();
    $person = Person::where('n_code', $user->n_code)->firstOrFail();

    // Set cache AFTER person creation
    Cache::put('hr_stats_version', 0);
    Cache::put('dashboard_version', 0);
    Cache::put('maps_version', 0);
    Cache::put('gis_version', 0);

    // Delete user first (FK constraint: users.n_code -> persons.n_code)
    // User uses SoftDeletes, so forceDelete to actually remove the row
    $user->forceDelete();
    $person->delete();

    $this->assertGreaterThan(0, Cache::get('hr_stats_version'));
    $this->assertGreaterThan(0, Cache::get('dashboard_version'));
    $this->assertGreaterThan(0, Cache::get('maps_version'));
    $this->assertGreaterThan(0, Cache::get('gis_version'));
});

it('batch deduplicates multiple gis increments into one', function () {
    Cache::put('gis_version', 0);
    Cache::put('hardware_stats_version', 0);

    $cache = app(CacheInvalidationServiceInterface::class);
    $cache->batch(function () use ($cache) {
        $cache->increment('gis');
        $cache->increment('gis');
        $cache->increment('hardware_stats');
    });

    // gis bumped once (deduplicated), hardware_stats bumped once
    $this->assertEquals(1, Cache::get('gis_version'));
    $this->assertEquals(1, Cache::get('hardware_stats_version'));
});
