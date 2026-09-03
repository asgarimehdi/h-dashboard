<?php

namespace Tests\Feature;

use App\Models\Boundary;
use App\Models\Person;
use App\Models\Region;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

covers(Boundary::class);

uses(TestCase::class, RefreshDatabase::class);

// Coverage gap (#494): Boundary::geojson has two execution paths — the
// pre-loaded-attribute fast path and the fallback query — plus its relations.
// Both paths hit real PostGIS functions, so they need a live pgsql test DB.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
});

function makeBoundary(): Boundary
{
    return Boundary::create([
        'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326)"),
    ]);
}

test('geojson accessor returns valid geometry from fallback query path', function () {
    $b = makeBoundary();

    // Fresh model: 'boundary' attribute not loaded as hex EWKB → fallback path.
    $fresh = Boundary::query()->findOrFail($b->id);

    $geojson = $fresh->geojson;

    expect($geojson)->not->toBeNull()
        ->and($geojson)->toContain('MultiPolygon')
        ->and(json_decode($geojson))->toBeObject();
});

test('geojson accessor uses preloaded boundary attribute without extra query', function () {
    $b = makeBoundary();

    // Simulate an eager-loaded raw EWKB attribute (the N+1 avoidance path).
    $raw = DB::table('boundaries')->where('id', $b->id)
        ->selectRaw('ST_AsEWKB(boundary) as boundary')->value('boundary');
    $hex = bin2hex(is_resource($raw) ? stream_get_contents($raw) : $raw);
    $model = new Boundary;
    $model->setRawAttributes([
        'id' => $b->id,
        'boundary' => $hex,
    ]);

    $geojson = $model->geojson;

    expect($geojson)->not->toBeNull()
        ->and($geojson)->toContain('MultiPolygon');
});

test('geojson returns null when boundary geometry is missing', function () {
    // Model instance carrying no geometry at all.
    $empty = new Boundary;
    $empty->setRawAttributes(['id' => 999999]);

    expect($empty->geojson)->toBeNull();
});

test('geojson returns null when boundary attribute key is present but empty', function () {
    // `array_key_exists('boundary', ...)` is true (key present) but the value
    // is falsy → exercises the early-return-null branch inside the first if.
    $empty = new Boundary;
    $empty->setRawAttributes(['id' => 1, 'boundary' => null]);

    expect($empty->geojson)->toBeNull();

    // Same branch via an empty-string EWKB value.
    $empty2 = new Boundary;
    $empty2->setRawAttributes(['id' => 1, 'boundary' => '']);

    expect($empty2->geojson)->toBeNull();
});

test('boundary belongs to region through boundary_id', function () {
    $b = makeBoundary();
    $region = Region::create([
        'name' => 'استان مرزی',
        'type' => 'province',
        'boundary_id' => $b->id,
    ]);

    expect($region->fresh()->boundary->id)->toBe($b->id)
        ->and(Boundary::count())->toBe(1);
});

test('boundary unit relation resolves owning unit', function () {
    $b = makeBoundary();
    Unit::create(['name' => 'واحد مرزدار', 'boundary_id' => $b->id]);

    $unit = $b->unit;

    expect($unit)->not->toBeNull()
        ->and($unit->name)->toBe('واحد مرزدار');
});

// ---------------------------------------------------------------------------
// Unit spatial scopes (#494 coverage gap): containingPoint, intersectsBoundary,
// withinDistance, withPersonnelCount — all exercised against live PostGIS.
// ---------------------------------------------------------------------------

test('scopeContainingPoint finds the unit whose polygon holds the point', function () {
    $b = makeBoundary(); // square (0,0)-(1,1)
    Unit::create(['name' => 'داخل مرز', 'boundary_id' => $b->id]);
    Unit::create(['name' => 'بدون مرز']);

    $hits = Unit::query()->containingPoint(0.5, 0.5)->pluck('name');

    expect($hits)->toContain('داخل مرز')
        ->and($hits)->not->toContain('بدون مرز');
});

test('scopeContainingPoint misses points outside the polygon', function () {
    $b = makeBoundary();
    Unit::create(['name' => 'داخل مرز', 'boundary_id' => $b->id]);

    expect(Unit::query()->containingPoint(5, 5)->count())->toBe(0);
});

test('scopeIntersectsBoundary matches overlapping polygons', function () {
    $b = makeBoundary();
    Unit::create(['name' => 'همپوشان', 'boundary_id' => $b->id]);

    // Square (0.5,0.5)-(2,2) overlaps the stored (0,0)-(1,1) square.
    $wkt = 'MULTIPOLYGON(((0.5 0.5, 2 0.5, 2 2, 0.5 2, 0.5 0.5)))';
    $hits = Unit::query()->intersectsBoundary($wkt)->pluck('name');

    expect($hits)->toContain('همپوشان');
});

test('scopeIntersectsBoundary ignores disjoint polygons', function () {
    $b = makeBoundary();
    Unit::create(['name' => 'دور', 'boundary_id' => $b->id]);

    $wkt = 'MULTIPOLYGON(((10 10, 11 10, 11 11, 10 11, 10 10)))';

    expect(Unit::query()->intersectsBoundary($wkt)->count())->toBe(0);
});

test('scopeWithPersonnelCount counts personnel per unit', function () {
    $b = makeBoundary();
    $unit = Unit::create(['name' => 'پرنفر', 'boundary_id' => $b->id]);

    foreach (['2222222222', '3333333333'] as $i => $n) {
        Person::create([
            'n_code' => $n, 'f_name' => 'الف'.$i, 'l_name' => 'ب',
            'u_id' => $unit->id, 's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
        ]);
    }

    $row = Unit::query()->withPersonnelCount()->find($unit->id);

    expect($row->personnel_count)->toBe(2);
});

test('scopeWithinDistance finds units near a point using PostGIS', function () {
    $b = makeBoundary(); // square around (0,0)
    Unit::create(['name' => 'نزدیک', 'boundary_id' => $b->id]);

    // ~111 km per degree of latitude: 0.1° ≈ 11 km.
    $hits = Unit::query()->withinDistance(0.05, 0.05, 20000)->pluck('name');

    expect($hits)->toContain('نزدیک')
        ->and(Unit::query()->withinDistance(5, 5, 1000)->count())->toBe(0);
});
