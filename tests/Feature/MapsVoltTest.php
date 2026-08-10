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

uses(RefreshDatabase::class);

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
    // SQLite does not ship PostGIS. Register lightweight stubs for the GIS
    // functions the county page (and its boundary insert) rely on so the page
    // can render in the test environment.
    $pdo = DB::connection()->getPdo();
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = null) => $wkt, 2);
    $pdo->sqliteCreateFunction('ST_AsGeoJSON', fn ($geom) => $geom, 1);

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
