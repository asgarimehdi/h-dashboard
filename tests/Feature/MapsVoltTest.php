<?php

namespace Tests\Feature;

use App\Models\Boundary;
use App\Models\Region;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(\App\Models\Unit::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->unit = Unit::create([
        'name' => 'واحد تست',
        'lat' => 35.6892,
        'lng' => 51.3890,
    ]);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('map');

    Session::put('current_unit_id', $this->unit->id);
});

test('map pages are protected by map permission', function () {
    $urls = [
        '/maps/route',
        '/maps/route2',
        '/maps/county',
        '/maps/unit',
        '/maps/interactive',
        '/maps/point',
    ];

    // Anonymous -> redirect to login
    foreach ($urls as $url) {
        $this->get($url)->assertRedirect('/login');
    }

    // Authenticated without 'map' permission -> 403
    $noPermUser = User::factory()->create();
    $this->actingAs($noPermUser);
    foreach ($urls as $url) {
        $this->get($url)->assertStatus(403);
    }
});

test('county map page renders shared map container', function () {
    // PostGIS is enabled in the test DB, so ST_GeomFromText / ST_AsGeoJSON
    // are available natively — no SQLite stub needed.
    // Provide a region + boundary for county page
    $boundary = Boundary::create([
        'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326)"),
    ]);
    $region = Region::create([
        'name' => 'شهرستان تست',
        'type' => 'county',
        'boundary_id' => $boundary->id,
    ]);
    $this->unit->update(['region_id' => $region->id]);

    Livewire::actingAs($this->user)
        ->test('maps.county')
        ->assertOk()
        ->assertSeeHtml('id="map"')
        ->assertSee('تقسیم‌بندی شهرستان');
});

test('all map components mount successfully for user with map permission', function () {
    $components = [
        'maps.route' => 'نقشه مسیر',
        'maps.route2' => 'نقشه مسیر',
        'maps.unit' => 'واحد',
        'maps.interactive' => 'تعاملی',
        'maps.point' => 'مکان‌ها',
    ];

    foreach ($components as $component => $title) {
        Livewire::actingAs($this->user)
            ->test($component)
            ->assertOk();
    }
});
