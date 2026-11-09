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
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1245 — the access contract of the three disease endpoints (spec §4.1/§6).
 *
 * Aggregate payloads are PII-free by construction, the read gate is the
 * existing `gis` group (`gis:read` + `map|bw`), `manage_diseases` is a write
 * permission with no bearing on these reads, the scope is fail-closed, and
 * every route under `api/gis/disease*` is a read.
 */
class DiseaseAccessTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * The keys that must never appear in any disease payload (decision 18).
     *
     * @var list<string>
     */
    private const PII_KEYS = [
        'patient_id', 'kod_melli', 'first_name', 'last_name',
        'birth_date', 'gender', 'city', 'fields',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        Cache::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
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

    private function boundary(string $wkt): int
    {
        return (int) DB::table('boundaries')->insertGetId([
            'boundary' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function square(float $minLon, float $minLat, float $maxLon, float $maxLat): string
    {
        return sprintf(
            'MULTIPOLYGON(((%F %F,%F %F,%F %F,%F %F,%F %F)))',
            $minLon, $minLat, $maxLon, $minLat, $maxLon, $maxLat, $minLon, $maxLat, $minLon, $minLat
        );
    }

    private function region(string $type, ?Region $parent, ?int $boundaryId): Region
    {
        return Region::query()->create([
            'name' => $type.'-'.fake()->unique()->numberBetween(1, 100000),
            'parent_id' => $parent?->id,
            'type' => $type,
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

    private function baseUnit(Unit $parent, ?Region $region, string $name): Unit
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
     * province P (boundary) → county C1 (boundary).
     *
     * `houseX` sits on C1 under the center-A branch; `houseY` sits on C1 under
     * the sibling center-B branch. One case, recorded by center-A staff, on
     * houseX — the mandatory V2/V5 shape of spec §4.1.
     *
     * @return array{province: Unit, centerA: Unit, centerB: Unit, houseX: Unit, houseY: Unit, disease: Disease, provinceToken: string, centerBToken: string}
     */
    private function visibilityFixture(): array
    {
        $province = Unit::factory()->create();
        $centerA = Unit::factory()->create(['parent_id' => $province->id]);
        $centerB = Unit::factory()->create(['parent_id' => $province->id]);

        $this->ensureUnitType(9, 'خانه بهداشت');

        $p = $this->region('province', null, $this->boundary($this->square(48, 36, 49, 37)));
        $c1 = $this->region('county', $p, $this->boundary($this->square(48, 36, 48.5, 36.5)));

        $houseX = $this->baseUnit($centerA, $c1, 'House X');
        $houseY = $this->baseUnit($centerB, $c1, 'House Y');

        $disease = Disease::factory()->create();
        $this->caseOn('2026-04-01', $houseX, $disease, $centerA);

        return [
            'province' => $province,
            'centerA' => $centerA,
            'centerB' => $centerB,
            'houseX' => $houseX,
            'houseY' => $houseY,
            'disease' => $disease,
            'provinceToken' => $this->tokenForUnit($province),
            'centerBToken' => $this->tokenForUnit($centerB),
        ];
    }

    private function mapQuery(array $fixture, array $query = []): string
    {
        $query = array_merge(['disease' => $fixture['disease']->id, 'zoom' => 8], $query);

        return '/api/gis/disease-map?'.http_build_query($query);
    }

    public function test_all_three_payloads_carry_only_the_pii_whitelist(): void
    {
        $fixture = $this->visibilityFixture();

        $diseases = $this->apiGet('/api/gis/diseases', $fixture['provinceToken'])->assertOk();
        $stats = $this->apiGet(
            "/api/gis/disease-stats?disease={$fixture['disease']->id}",
            $fixture['provinceToken']
        )->assertOk();
        $regionMap = $this->apiGet($this->mapQuery($fixture), $fixture['provinceToken'])->assertOk();
        $unitMap = $this->apiGet(
            $this->mapQuery($fixture, ['zoom' => DiseaseMapService::ZOOM_SWITCH]),
            $fixture['provinceToken']
        )->assertOk();

        foreach ([$diseases, $stats, $regionMap, $unitMap] as $response) {
            foreach (self::PII_KEYS as $key) {
                $this->assertStringNotContainsString($key, $response->getContent());
            }
        }

        // The catalogue is exactly the three public columns.
        $this->assertSame(['id', 'name_fa', 'slug'], array_keys($diseases->json()[0]));

        // The stats envelope is exactly cases / population / rate.
        $this->assertSame(
            ['cases', 'population', 'rate'],
            array_keys($stats->json())
        );

        // Region feature properties are the exact whitelist, with the exact
        // meta keys beside them.
        foreach ($regionMap->json('features') as $feature) {
            $this->assertSame(
                ['type', 'id', 'geometry', 'properties'],
                array_keys($feature)
            );
            $this->assertSame(
                ['level', 'region_id', 'name', 'cases', 'population', 'rate', 'class'],
                array_keys($feature['properties'])
            );
        }
        $this->assertSame(
            ['level', 'zoom_switch', 'classes', 'breaks', 'fallback_provinces', 'unmapped_cases'],
            array_keys($regionMap->json('meta'))
        );

        // Unit markers are the four-column whitelist too.
        foreach ($unitMap->json('features') as $feature) {
            $this->assertSame(
                ['level', 'unit_id', 'name', 'cases'],
                array_keys($feature['properties'])
            );
        }
        $this->assertSame(
            ['level', 'zoom_switch', 'classes', 'breaks', 'fallback_provinces', 'unmapped_cases'],
            array_keys($unitMap->json('meta'))
        );
    }

    public function test_map_reads_do_not_require_manage_diseases(): void
    {
        // A plain `map` holder reads every aggregate — `manage_diseases` is a
        // write permission and these endpoints have no write.
        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($unit);
        $disease = Disease::factory()->create();

        $this->apiGet('/api/gis/diseases', $token)->assertOk();
        $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token)->assertOk();
        $this->apiGet("/api/gis/disease-map?disease={$disease->id}", $token)->assertOk();
    }

    public function test_manage_diseases_alone_cannot_read_the_disease_endpoints(): void
    {
        // `manage_diseases` is owned by #1242 and deliberately NOT seeded;
        // create it here only to prove the read gate ignores it. Adding it to
        // the seeder from this test would leak a write permission everywhere.
        Permission::firstOrCreate(['name' => 'manage_diseases']);

        $unit = Unit::factory()->create();
        $user = User::factory()->create();
        $user->person()->update(['u_id' => $unit->id]);
        $user->givePermissionTo('manage_diseases');
        $token = $this->createApiToken($user, ['gis:read']);
        $disease = Disease::factory()->create();

        $this->apiGet('/api/gis/diseases', $token)->assertForbidden();
        $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token)->assertForbidden();
        $this->apiGet("/api/gis/disease-map?disease={$disease->id}", $token)->assertForbidden();
    }

    public function test_an_empty_scope_sees_zero_and_no_features(): void
    {
        $disease = Disease::factory()->create();

        // A case exists in the world — the empty scope must not see it.
        $unit = Unit::factory()->create();
        $this->caseOn('2026-04-01', $unit, $disease);

        $user = User::factory()->create();
        $user->person()->update(['u_id' => null]);
        $user->givePermissionTo('map');
        $token = $this->createApiToken($user, ['gis:read']);

        $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token)
            ->assertOk()
            ->assertJson(['cases' => 0, 'population' => null, 'rate' => null]);

        $this->apiGet("/api/gis/disease-map?disease={$disease->id}", $token)
            ->assertOk()
            ->assertJsonCount(0, 'features');
    }

    public function test_the_disease_gis_routes_are_read_only(): void
    {
        $routes = collect(app(Router::class)->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/gis/disease'))
            ->values();

        $this->assertSame(
            ['api/gis/disease-map', 'api/gis/disease-stats', 'api/gis/diseases'],
            $routes->map(fn (Route $route) => $route->uri())->sort()->values()->all()
        );

        foreach ($routes as $route) {
            $this->assertContains('GET', $route->methods());
            $this->assertEmpty(
                array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()),
                "Route '{$route->uri()}' must not accept a write method."
            );
            $this->assertSame(
                DiseaseMapController::class,
                $route->getControllerClass(),
                "Route '{$route->uri()}' must be served by the aggregate controller."
            );
        }
    }

    public function test_an_unrelated_house_sees_none_of_another_houses_cases(): void
    {
        $fixture = $this->visibilityFixture();

        // V2: the staff-level record about houseX is visible up the tree —
        // the province sees the case in stats and in the county polygon.
        $stats = $this->apiGet(
            "/api/gis/disease-stats?disease={$fixture['disease']->id}",
            $fixture['provinceToken']
        )->assertOk();
        $this->assertSame(1, $stats->json('cases'));

        $map = $this->apiGet($this->mapQuery($fixture), $fixture['provinceToken'])->assertOk();
        $this->assertCount(1, $map->json('features'));
        $this->assertSame(1, $map->json('features.0.properties.cases'));

        // V5: houseY's branch sees none of it — zero cases, a zero-count
        // polygon, and houseX absent from the marker layer.
        $otherStats = $this->apiGet(
            "/api/gis/disease-stats?disease={$fixture['disease']->id}",
            $fixture['centerBToken']
        )->assertOk();
        $this->assertSame(0, $otherStats->json('cases'));

        $otherMap = $this->apiGet($this->mapQuery($fixture), $fixture['centerBToken'])->assertOk();

        foreach ($otherMap->json('features') as $feature) {
            $this->assertSame(0, $feature['properties']['cases']);
        }

        $this->assertSame(0, $otherMap->json('meta.unmapped_cases'));

        $unitMap = $this->apiGet(
            $this->mapQuery($fixture, ['zoom' => DiseaseMapService::ZOOM_SWITCH]),
            $fixture['centerBToken']
        )->assertOk();

        $this->assertSame(
            ['unit:'.$fixture['houseY']->id],
            array_column($unitMap->json('features'), 'id')
        );
    }
}
