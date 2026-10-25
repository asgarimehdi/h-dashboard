<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DiseaseMapController;
use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Region;
use App\Models\Unit;
use App\Models\User;
use App\Services\Disease\DiseaseMapService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1245 — the three `GET /api/gis/disease*` endpoints (spec §6).
 *
 * Every request carries a real Bearer token (`InteractsWithApiTokens`) so the
 * `ability:gis:read` middleware is actually exercised. The aggregate payloads
 * are PII-free by construction: counts, rates and organizational names only.
 */
covers(DiseaseMapController::class);

class DiseaseApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        Cache::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * A real Bearer token minted the way the Flutter app gets one.
     *
     * @param  array<int, string>  $permissions
     * @param  array<int, string>|null  $abilities  null → the user's own abilities
     */
    private function tokenFor(array $permissions = ['map'], ?array $abilities = null): string
    {
        ['user' => $user] = $this->createUserWithUnit($permissions);

        return $this->createApiToken($user, $abilities ?? ['gis:read']);
    }

    private function tokenForUnit(Unit $unit, array $permissions = ['map']): string
    {
        $user = User::factory()->create();
        $user->person()->update(['u_id' => $unit->id]);
        $user->givePermissionTo($permissions);

        return $this->createApiToken($user, ['gis:read']);
    }

    private function caseOn(string $diagnosedAt, Unit $subject, Disease $disease, ?Unit $recorder = null): DiseaseCase
    {
        return DiseaseCase::factory()->create([
            'disease_id' => $disease->id,
            'unit_id' => ($recorder ?? $subject)->id,
            'subject_unit_id' => $subject->id,
            'diagnosed_at' => $diagnosedAt,
        ]);
    }

    public function test_unauthenticated_disease_endpoints_return_401(): void
    {
        $this->getJson('/api/gis/diseases')->assertUnauthorized();
        $this->getJson('/api/gis/disease-stats')->assertUnauthorized();
        $this->getJson('/api/gis/disease-map')->assertUnauthorized();
    }

    public function test_diseases_returns_active_only_sorted(): void
    {
        $third = Disease::factory()->create(['sort' => 2, 'is_active' => true]);
        $inactive = Disease::factory()->create(['sort' => 1, 'is_active' => false]);
        $first = Disease::factory()->create(['sort' => 0, 'is_active' => true]);
        $second = Disease::factory()->create(['sort' => 1, 'is_active' => true]);

        $response = $this->apiGet('/api/gis/diseases', $this->tokenFor());

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($payload, 'id')
        );
        $this->assertNotContains($inactive->id, array_column($payload, 'id'));
        $this->assertSame(['id', 'name_fa', 'slug'], array_keys($payload[0]));
        $this->assertSame($first->name_fa, $payload[0]['name_fa']);
        $this->assertSame($first->slug, $payload[0]['slug']);
    }

    public function test_diseases_requires_gis_ability(): void
    {
        // A `map` holder whose token was minted without `gis:read` is stopped
        // by the ability middleware, not by the permission gate.
        $token = $this->tokenFor(['map'], ['units:read']);

        $this->apiGet('/api/gis/diseases', $token)->assertForbidden();
    }

    public function test_diseases_requires_map_permission(): void
    {
        // A token carrying `gis:read` for a user with neither `map` nor `bw`
        // is stopped by the route gate.
        $token = $this->tokenFor([], ['gis:read']);

        $this->apiGet('/api/gis/diseases', $token)->assertForbidden();
    }

    public function test_disease_stats_requires_a_disease(): void
    {
        $this->apiGet('/api/gis/disease-stats', $this->tokenFor())
            ->assertStatus(422)
            ->assertJsonValidationErrors('disease');
    }

    public function test_disease_stats_rejects_an_unknown_disease(): void
    {
        $this->apiGet('/api/gis/disease-stats?disease=999999', $this->tokenFor())
            ->assertStatus(422)
            ->assertJsonValidationErrors('disease');
    }

    public function test_disease_stats_accepts_an_inactive_disease(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $inactive = Disease::factory()->create(['is_active' => false]);
        $this->caseOn('2026-04-01', $unit, $inactive);

        $response = $this->apiGet(
            "/api/gis/disease-stats?disease={$inactive->id}&from=1405/01/01&to=1405/12/29",
            $this->tokenForUnit($unit)
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('cases'));
    }

    public function test_disease_stats_rejects_an_impossible_jalali_date(): void
    {
        $disease = Disease::factory()->create();

        $this->apiGet(
            "/api/gis/disease-stats?disease={$disease->id}&from=1405/13/01",
            $this->tokenFor()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_disease_stats_rejects_an_inverted_range(): void
    {
        $disease = Disease::factory()->create();

        $this->apiGet(
            "/api/gis/disease-stats?disease={$disease->id}&from=1405/06/01&to=1405/05/01",
            $this->tokenFor()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_disease_stats_defaults_to_the_current_jalali_year(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($unit);
        $disease = Disease::factory()->create();

        // 1404/12/29 — the day before the current Persian year.
        $this->caseOn('2026-03-20', $unit, $disease);
        // 1405/01/01 and 1405/12/29 — first and last day of the year.
        $this->caseOn('2026-03-21', $unit, $disease);
        $this->caseOn('2027-03-20', $unit, $disease);
        // 1406/01/01 — the day after.
        $this->caseOn('2027-03-21', $unit, $disease);

        $response = $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token);

        $response->assertOk();
        $this->assertSame(2, $response->json('cases'));
    }

    public function test_disease_stats_filters_by_the_requested_range(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($unit);
        $disease = Disease::factory()->create();

        // 1405/01/10, 1405/01/15 and 1405/01/20.
        $this->caseOn('2026-03-30', $unit, $disease);
        $this->caseOn('2026-04-04', $unit, $disease);
        $this->caseOn('2026-04-09', $unit, $disease);

        $response = $this->apiGet(
            "/api/gis/disease-stats?disease={$disease->id}&from=1405/01/11&to=1405/01/19",
            $token
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('cases'));
    }

    public function test_disease_stats_empty_scope_is_zero_and_null(): void
    {
        $user = User::factory()->create();
        $user->person()->update(['u_id' => null]);
        $user->givePermissionTo('map');
        $token = $this->createApiToken($user, ['gis:read']);
        $disease = Disease::factory()->create();

        $response = $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token);

        $response->assertOk();
        $this->assertSame(
            ['cases' => 0, 'population' => null, 'rate' => null],
            $response->json()
        );
    }

    // ---------------------------------------------------------------------
    // #1245 Task 4 — region choropleth (zoom < ZOOM_SWITCH)
    // ---------------------------------------------------------------------

    private function boundary(string $wkt): int
    {
        DB::table('boundaries')->insert([
            'boundary' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    private function square(float $minLon, float $minLat, float $maxLon, float $maxLat): string
    {
        return "MULTIPOLYGON((({$minLon} {$minLat},{$maxLon} {$minLat},{$maxLon} {$maxLat},{$minLon} {$maxLat},{$minLon} {$minLat})))";
    }

    private function region(string $type, ?Region $parent, ?int $boundaryId): Region
    {
        return Region::query()->create([
            'name' => $type.'-'.fake()->unique()->numberBetween(1, 100000),
            'type' => $type,
            'parent_id' => $parent?->id,
            'boundary_id' => $boundaryId,
        ]);
    }

    private function ensureUnitType(int $id, string $name): void
    {
        if (! DB::table('unit_types')->where('id', $id)->exists()) {
            DB::table('unit_types')->insert([
                'id' => $id,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function baseUnit(Unit $parent, ?Region $region, string $name = 'خانه بهداشت'): Unit
    {
        return Unit::factory()->create([
            'name' => $name,
            'parent_id' => $parent->id,
            'region_id' => $region?->id,
            'unit_type_id' => 9,
            'is_active' => true,
            'lat' => 36.5,
            'lng' => 48.5,
        ]);
    }

    /**
     * province P (boundary) → C1 (boundary) + C2 (no boundary)  → fallback
     * province Q (boundary) → Q1 (boundary) + Q2 (boundary)      → counties
     * province R (no boundary) → R1 (boundary)                   → counties
     *
     * @return array{token: string, disease: Disease, units: array<string, Unit>, regions: array<string, Region>}
     */
    private function regionFixture(): array
    {
        ['unit' => $root] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($root);
        $disease = Disease::factory()->create();

        $this->ensureUnitType(9, 'خانه بهداشت');

        $p = $this->region('province', null, $this->boundary($this->square(48, 36, 49, 37)));
        $c1 = $this->region('county', $p, $this->boundary($this->square(48, 36, 48.5, 36.5)));
        $c2 = $this->region('county', $p, null);

        $q = $this->region('province', null, $this->boundary($this->square(50, 36, 51, 37)));
        $q1 = $this->region('county', $q, $this->boundary($this->square(50, 36, 50.5, 36.5)));
        // Two parts on purpose: the geometry must stay a MultiPolygon.
        $q2 = $this->region('county', $q, $this->boundary(
            'MULTIPOLYGON(((50.5 36.5,51 36.5,51 37,50.5 37,50.5 36.5)),((50.6 36.6,50.7 36.6,50.7 36.7,50.6 36.7,50.6 36.6)))'
        ));

        $r = $this->region('province', null, null);
        $r1 = $this->region('county', $r, $this->boundary($this->square(52, 36, 53, 37)));

        $unitC1 = $this->baseUnit($root, $c1, 'C1 base');
        $unitC2 = $this->baseUnit($root, $c2, 'C2 base');
        $unitQ1 = $this->baseUnit($root, $q1, 'Q1 base');
        $unitQ2 = $this->baseUnit($root, $q2, 'Q2 base');
        $unitR1 = $this->baseUnit($root, $r1, 'R1 base');
        $unitProvince = $this->baseUnit($root, $p, 'P direct base');
        $unitUnmapped = $this->baseUnit($root, null, 'No region base');

        $this->caseOn('2026-04-01', $unitC1, $disease, $root);
        $this->caseOn('2026-04-02', $unitC2, $disease, $root);
        $this->caseOn('2026-04-03', $unitQ1, $disease, $root);
        $this->caseOn('2026-04-04', $unitProvince, $disease, $root);
        $this->caseOn('2026-04-05', $unitUnmapped, $disease, $root);

        DB::table('unit_populations')->insert([
            ['unit_id' => $unitC1->id, 'year' => 2026, 'population' => 1000, 'created_at' => now(), 'updated_at' => now()],
            ['unit_id' => $unitQ1->id, 'year' => 2026, 'population' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [
            'token' => $token,
            'disease' => $disease,
            'root' => $root,
            'units' => compact('unitC1', 'unitC2', 'unitQ1', 'unitQ2', 'unitR1', 'unitProvince', 'unitUnmapped'),
            'regions' => compact('p', 'c1', 'c2', 'q', 'q1', 'q2', 'r', 'r1'),
        ];
    }

    private function mapFor(array $fixture, array $query = []): TestResponse
    {
        $query = array_merge(['disease' => $fixture['disease']->id, 'zoom' => 8], $query);

        return $this->apiGet('/api/gis/disease-map?'.http_build_query($query), $fixture['token']);
    }

    /** @return array<int, array<string, mixed>> */
    private function featuresByRegion(TestResponse $response): array
    {
        $byRegion = [];

        foreach ($response->json('features') as $feature) {
            $byRegion[$feature['properties']['region_id']] = $feature;
        }

        return $byRegion;
    }

    public function test_region_map_returns_polygon_features(): void
    {
        $fixture = $this->regionFixture();

        $response = $this->mapFor($fixture);

        $response->assertOk();
        $this->assertSame('FeatureCollection', $response->json('type'));

        foreach ($response->json('features') as $feature) {
            $this->assertContains($feature['geometry']['type'], ['Polygon', 'MultiPolygon']);
            $this->assertContains($feature['properties']['level'], ['county', 'province']);
        }
    }

    public function test_region_map_falls_back_per_province_all_or_nothing(): void
    {
        $fixture = $this->regionFixture();

        $response = $this->mapFor($fixture);
        $features = $this->featuresByRegion($response);

        // P has one county without a boundary → one province feature summing
        // both counties plus the province's own direct row.
        $this->assertArrayHasKey($fixture['regions']['p']->id, $features);
        $this->assertSame('province', $features[$fixture['regions']['p']->id]['properties']['level']);
        $this->assertSame(3, $features[$fixture['regions']['p']->id]['properties']['cases']);
        $this->assertArrayNotHasKey($fixture['regions']['c1']->id, $features);
        $this->assertArrayNotHasKey($fixture['regions']['c2']->id, $features);

        // Q has complete county coverage → counties, no province feature.
        $this->assertSame('county', $features[$fixture['regions']['q1']->id]['properties']['level']);
        $this->assertSame('county', $features[$fixture['regions']['q2']->id]['properties']['level']);
        $this->assertArrayNotHasKey($fixture['regions']['q']->id, $features);

        $this->assertSame('mixed', $response->json('meta.level'));
        $this->assertSame(1, $response->json('meta.fallback_provinces'));
    }

    public function test_region_feature_properties_are_the_whitelist(): void
    {
        $fixture = $this->regionFixture();

        foreach ($this->mapFor($fixture)->json('features') as $feature) {
            $this->assertSame(
                ['level', 'region_id', 'name', 'cases', 'population', 'rate', 'class'],
                array_keys($feature['properties'])
            );
            $this->assertSame('region:'.$feature['properties']['region_id'], $feature['id']);
        }
    }

    public function test_region_rate_and_class_follow_population(): void
    {
        $fixture = $this->regionFixture();

        $features = $this->featuresByRegion($this->mapFor($fixture));

        $p = $features[$fixture['regions']['p']->id]['properties'];
        $this->assertSame(1000, $p['population']);
        // JSON drops the `.0`, so compare numerically (plan's test note).
        $this->assertEquals(30.0, $p['rate']);
        $this->assertNotNull($p['class']);

        $q1 = $features[$fixture['regions']['q1']->id]['properties'];
        $this->assertSame(0, $q1['population']);
        $this->assertNull($q1['rate']);
        $this->assertNull($q1['class']);

        $q2 = $features[$fixture['regions']['q2']->id]['properties'];
        $this->assertNull($q2['population']);
        $this->assertNull($q2['rate']);
        $this->assertNull($q2['class']);
    }

    public function test_region_geometry_keeps_islands_and_unwraps_single_parts(): void
    {
        $fixture = $this->regionFixture();

        $features = $this->featuresByRegion($this->mapFor($fixture));

        $p = $features[$fixture['regions']['p']->id];
        $this->assertSame('Polygon', $p['geometry']['type']);

        $q2 = $features[$fixture['regions']['q2']->id];
        $this->assertSame('MultiPolygon', $q2['geometry']['type']);
        $this->assertCount(2, $q2['geometry']['coordinates']);

        foreach ($features as $feature) {
            $this->assertNotNull($feature['geometry']);
            $this->assertNotEmpty($feature['geometry']['coordinates']);
        }
    }

    public function test_region_without_boundary_is_dropped_not_centroided(): void
    {
        $fixture = $this->regionFixture();

        $features = $this->featuresByRegion($this->mapFor($fixture));

        $this->assertArrayNotHasKey($fixture['regions']['r']->id, $features);
        $this->assertArrayHasKey($fixture['regions']['r1']->id, $features);
    }

    public function test_region_bbox_filters_features_without_changing_meta(): void
    {
        $fixture = $this->regionFixture();

        $response = $this->mapFor($fixture, ['bbox' => '49.5,35.5,51.5,37.5']);

        $response->assertOk();
        $features = $this->featuresByRegion($response);

        // Only province Q and its two counties fall inside the envelope.
        $this->assertArrayHasKey($fixture['regions']['q1']->id, $features);
        $this->assertArrayHasKey($fixture['regions']['q2']->id, $features);
        $this->assertArrayNotHasKey($fixture['regions']['p']->id, $features);
        $this->assertArrayNotHasKey($fixture['regions']['r1']->id, $features);
        $this->assertSame('mixed', $response->json('meta.level'));
    }

    public function test_region_map_never_leaks_pii(): void
    {
        $fixture = $this->regionFixture();

        $response = $this->mapFor($fixture);
        $content = $response->getContent();

        foreach (['patient_id', 'kod_melli', 'first_name', 'last_name', 'birth_date', 'gender', 'city', 'fields'] as $key) {
            $this->assertStringNotContainsString($key, $content);
        }

        foreach ($response->json('features') as $feature) {
            $this->assertSame([], array_diff(array_keys($feature['properties']), [
                'level', 'region_id', 'name', 'cases', 'population', 'rate', 'class',
            ]));
        }
    }

    public function test_region_map_reports_unmapped_cases(): void
    {
        $fixture = $this->regionFixture();

        $response = $this->mapFor($fixture);

        $this->assertSame(1, $response->json('meta.unmapped_cases'));

        $totalCases = 0;
        foreach ($response->json('features') as $feature) {
            $totalCases += $feature['properties']['cases'];
        }
        $this->assertSame(4, $totalCases);
    }

    public function test_region_map_validates_zoom_and_bbox(): void
    {
        $fixture = $this->regionFixture();

        $this->mapFor($fixture, ['bbox' => '48,36,49'])->assertStatus(422)->assertJsonValidationErrors('bbox');
        $this->mapFor($fixture, ['bbox' => '49,36,48,37'])->assertStatus(422)->assertJsonValidationErrors('bbox');
        $this->mapFor($fixture, ['zoom' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('zoom');
        $this->mapFor($fixture, ['zoom' => -1])->assertStatus(422)->assertJsonValidationErrors('zoom');
        $this->mapFor($fixture, ['zoom' => 23])->assertStatus(422)->assertJsonValidationErrors('zoom');
    }

    public function test_region_map_empty_scope_is_an_empty_collection(): void
    {
        $user = User::factory()->create();
        $user->person()->update(['u_id' => null]);
        $user->givePermissionTo('map');
        $token = $this->createApiToken($user, ['gis:read']);
        $disease = Disease::factory()->create();

        $response = $this->apiGet(
            "/api/gis/disease-map?disease={$disease->id}&zoom=8",
            $token
        );

        $response->assertOk();
        $this->assertSame('FeatureCollection', $response->json('type'));
        $this->assertCount(0, $response->json('features'));
        $this->assertSame('none', $response->json('meta.level'));
        $this->assertSame(0, $response->json('meta.unmapped_cases'));
    }

    // ---------------------------------------------------------------------
    // #1245 Task 5 — unit markers (zoom >= ZOOM_SWITCH)
    // ---------------------------------------------------------------------

    /**
     * houseA / houseB / houseC are base units; houseNoGeo and houseInactive are
     * filtered out; `center` is not a base type; `outside` is out of scope.
     *
     * @return array<string, mixed>
     */
    private function unitFixture(): array
    {
        ['unit' => $root] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($root);
        $disease = Disease::factory()->create();

        $this->ensureUnitType(9, 'خانه بهداشت');
        $this->ensureUnitType(1, 'ستاد');

        $houseA = $this->baseUnit($root, null, 'House A');
        $houseA->update(['lat' => 36.5, 'lng' => 48.5]);

        $houseB = $this->baseUnit($root, null, 'House B');
        $houseB->update(['lat' => 37.5, 'lng' => 49.5]);

        $houseC = $this->baseUnit($root, null, 'House C');
        $houseC->update(['lat' => 38.5, 'lng' => 50.5]);

        $houseNoGeo = $this->baseUnit($root, null, 'No geo');
        $houseNoGeo->update(['lat' => null, 'lng' => null]);

        $houseInactive = $this->baseUnit($root, null, 'Inactive');
        $houseInactive->update(['is_active' => false]);

        $center = Unit::factory()->create([
            'name' => 'Center',
            'parent_id' => $root->id,
            'unit_type_id' => 1,
            'lat' => 36.6,
            'lng' => 48.6,
        ]);

        $outsideRoot = Unit::factory()->create();
        $outside = Unit::factory()->create([
            'parent_id' => $outsideRoot->id,
            'unit_type_id' => 9,
            'lat' => 36.7,
            'lng' => 48.7,
        ]);

        $this->caseOn('2026-04-01', $houseA, $disease, $root);
        $this->caseOn('2026-04-02', $houseB, $disease, $root);
        $this->caseOn('2026-04-03', $houseNoGeo, $disease, $root);
        $this->caseOn('2026-04-04', $houseInactive, $disease, $root);
        $this->caseOn('2026-04-05', $center, $disease, $root);
        $this->caseOn('2026-04-06', $outside, $disease, $root);

        return [
            'token' => $token,
            'disease' => $disease,
            'units' => compact('houseA', 'houseB', 'houseC', 'houseNoGeo', 'houseInactive', 'center', 'outside'),
        ];
    }

    public function test_unit_map_returns_points_for_every_base_unit(): void
    {
        $fixture = $this->unitFixture();

        $response = $this->mapFor($fixture, ['zoom' => DiseaseMapService::ZOOM_SWITCH]);

        $response->assertOk();

        $features = $response->json('features');
        $this->assertCount(3, $features);

        $byId = [];
        foreach ($features as $feature) {
            $this->assertSame('Point', $feature['geometry']['type']);
            $this->assertSame('unit', $feature['properties']['level']);
            $this->assertSame(
                ['level', 'unit_id', 'name', 'cases'],
                array_keys($feature['properties'])
            );
            $this->assertSame('unit:'.$feature['properties']['unit_id'], $feature['id']);
            $byId[$feature['properties']['unit_id']] = $feature['properties'];
        }

        // Units without cases are present with 0 so the layer never looks empty.
        $this->assertSame(0, $byId[$fixture['units']['houseC']->id]['cases']);
        $this->assertSame(1, $byId[$fixture['units']['houseA']->id]['cases']);
        $this->assertSame([(float) 48.5, (float) 36.5], $features[0]['geometry']['coordinates']);
    }

    public function test_unit_map_skips_units_without_coordinates_or_inactive(): void
    {
        $fixture = $this->unitFixture();

        $response = $this->mapFor($fixture, ['zoom' => 9]);

        $ids = array_column($response->json('features'), 'id');

        $this->assertNotContains('unit:'.$fixture['units']['houseNoGeo']->id, $ids);
        $this->assertNotContains('unit:'.$fixture['units']['houseInactive']->id, $ids);
        $this->assertNotContains('unit:'.$fixture['units']['center']->id, $ids);
        $this->assertNotContains('unit:'.$fixture['units']['outside']->id, $ids);

        foreach ($response->json('features') as $feature) {
            $this->assertNotNull($feature['geometry']);
        }
    }

    public function test_unit_map_bbox_filters_on_coordinates(): void
    {
        $fixture = $this->unitFixture();

        $response = $this->mapFor($fixture, ['zoom' => 9, 'bbox' => '48,36,49,37']);

        $response->assertOk();

        $ids = array_column($response->json('features'), 'id');

        $this->assertSame(['unit:'.$fixture['units']['houseA']->id], $ids);
    }

    public function test_zoom_switch_separates_region_and_unit_modes(): void
    {
        $fixture = $this->unitFixture();

        $region = $this->mapFor($fixture, ['zoom' => DiseaseMapService::ZOOM_SWITCH - 1]);
        $unit = $this->mapFor($fixture, ['zoom' => DiseaseMapService::ZOOM_SWITCH]);

        foreach ($region->json('features') as $feature) {
            $this->assertNotSame('unit', $feature['properties']['level']);
        }

        foreach ($unit->json('features') as $feature) {
            $this->assertSame('Point', $feature['geometry']['type']);
        }

        $this->assertSame(9, DiseaseMapService::ZOOM_SWITCH);
        $this->assertSame(DiseaseMapService::ZOOM_SWITCH, $region->json('meta.zoom_switch'));
        $this->assertSame(DiseaseMapService::ZOOM_SWITCH, $unit->json('meta.zoom_switch'));
    }

    public function test_unit_map_meta_has_no_breaks(): void
    {
        $fixture = $this->unitFixture();

        $response = $this->mapFor($fixture, ['zoom' => 9]);

        $this->assertSame('unit', $response->json('meta.level'));
        $this->assertSame([], $response->json('meta.breaks'));
        $this->assertSame(0, $response->json('meta.classes'));
        $this->assertSame(0, $response->json('meta.fallback_provinces'));
        // 4 in-scope cases (the inactive unit is outside the scope); only
        // houseA (1) and houseB (1) sit on a marker.
        $this->assertSame(2, $response->json('meta.unmapped_cases'));
    }

    public function test_unit_map_never_leaks_pii(): void
    {
        $fixture = $this->unitFixture();

        $response = $this->mapFor($fixture, ['zoom' => 9]);

        foreach (['patient_id', 'kod_melli', 'first_name', 'last_name', 'birth_date', 'gender', 'city', 'fields'] as $key) {
            $this->assertStringNotContainsString($key, $response->getContent());
        }

        foreach ($response->json('features') as $feature) {
            $this->assertSame(
                ['level', 'unit_id', 'name', 'cases'],
                array_keys($feature['properties'])
            );
        }
    }
}
