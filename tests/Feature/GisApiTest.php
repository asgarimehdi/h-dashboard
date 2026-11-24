<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GisController;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(GisController::class);

class GisApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createChildUnit(Unit $parent, string $name, float $lat, float $lng): Unit
    {
        return Unit::create([
            'name' => $name,
            'parent_id' => $parent->id,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    /**
     * UnitFactory assigns random lat/lng that may fall outside the test bbox
     * (48-49E, 36-37N), hiding units/hardware/tickets from GIS responses.
     * Pin the user's unit inside the bbox after creation.
     */
    private function createBBoxUser(): array
    {
        $result = $this->createUserWithUnit(['map']);
        $result['unit']->update(['lat' => 36.669343, 'lng' => 48.47163]);

        return $result;
    }

    /** @test */
    public function test_unauthenticated_user_cannot_access_gis_apis(): void
    {
        $this->getJson('/api/gis/units')->assertStatus(401);
        $this->getJson('/api/gis/hardware')->assertStatus(401);
        $this->getJson('/api/gis/tickets')->assertStatus(401);
        $this->getJson('/api/gis/stats')->assertStatus(401);
        $this->getJson('/api/gis/clusters')->assertStatus(401);
    }

    /** @test */
    public function test_gis_units_returns_geojson_feature_collection(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $this->createChildUnit($unit, 'Child Unit', 36.7, 48.5);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/units?bbox=48,36,49,37', $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type', 'features' => [
                    '*' => ['type', 'id', 'geometry', 'properties'],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(2, $response->json('features'));
    }

    /** @test */
    public function test_gis_units_filters_by_bbox(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $unit->update(['lat' => 36.669343, 'lng' => 48.47163]);
        $this->createChildUnit($unit, 'Child In BBox', 36.67, 48.48);
        $this->createChildUnit($unit, 'Child Out BBox', 38.0, 50.0); // clearly outside

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/units?bbox=48,36,49,37', $token);

        // Parent unit + Child In BBox = 2; Child Out BBox filtered out
        $features = $response->json('features');
        $this->assertCount(2, $features);
        $names = array_column(array_column($features, 'properties'), 'name');
        $this->assertContains($unit->name, $names);
        $this->assertContains('Child In BBox', $names);
    }

    /** @test */
    public function test_gis_hardware_returns_geojson_feature_collection(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $person = Person::where('u_id', $unit->id)->first();

        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'laptop',
            'cpu' => 'Intel i5',
            'ram' => '8GB',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/hardware?bbox=48,36,49,37', $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type', 'features' => [
                    '*' => ['type', 'id', 'geometry', 'properties'],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
    }

    /** @test */
    public function test_gis_hardware_filters_by_type(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $person = Person::where('u_id', $unit->id)->first();

        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'Laptop-001',
            'type' => 'laptop',
        ]);
        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'desktop',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/hardware?bbox=48,36,49,37&type=laptop', $token);

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('laptop', $response->json('features.0.properties.type'));
    }

    /** @test */
    public function test_gis_tickets_returns_geojson_feature_collection(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Test Ticket',
            'content' => 'Test content',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/tickets?bbox=48,36,49,37', $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type', 'features' => [
                    '*' => ['type', 'id', 'geometry', 'properties'],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
    }

    /** @test */
    public function test_gis_tickets_filters_by_priority_and_status(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Urgent Ticket',
            'content' => 'Test',
            'priority' => 'urgent',
            'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-002',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Normal Ticket',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-003',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Completed Ticket',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'completed',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/tickets?bbox=48,36,49,37&priority=urgent', $token);

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('urgent', $response->json('features.0.properties.priority'));

        // Test status filter
        $response = $this->apiGet('/api/gis/tickets?bbox=48,36,49,37&status=completed', $token);

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('completed', $response->json('features.0.properties.status'));
    }

    /**
     * The GIS ticket layer serializes 6 fields only — the ticket body
     * (`content`) must never be hydrated into the response.
     *
     * @test
     */
    public function test_gis_tickets_response_omits_the_content_column(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $marker = 'SECRET-TICKET-BODY-MARKER';
        Ticket::create([
            'ticket_code' => 'TKT-LONG',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Long Content Ticket',
            'content' => str_repeat('متن بلند تیکت '.$marker.' ', 400),
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/tickets?bbox=48,36,49,37', $token);

        $response->assertStatus(200);

        $properties = $response->json('features.0.properties');
        $this->assertIsArray($properties);
        $this->assertCount(6, $properties);
        $this->assertEqualsCanonicalizing(
            ['id', 'ticket_code', 'title', 'priority', 'status', 'unit'],
            array_keys($properties)
        );

        $response->assertJsonMissingPath('features.0.properties.content');
        $this->assertStringNotContainsString($marker, $response->getContent());
    }

    /** @test */
    public function test_gis_stats_returns_counts(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $this->createChildUnit($unit, 'Child Unit', 36.67, 48.48);
        $person = Person::where('u_id', $unit->id)->first();

        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'laptop',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Test',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-002',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Test 2',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'completed',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/stats?bbox=48,36,49,37', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['units', 'hardware', 'open_tickets']);

        $this->assertEquals(2, $response->json('units'));
        $this->assertEquals(1, $response->json('hardware'));
        $this->assertEquals(1, $response->json('open_tickets')); // only non-completed
    }

    /** @test */
    public function test_gis_clusters_returns_clustered_data(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $this->createChildUnit($unit, 'Cluster Unit 1', 36.669, 48.471);
        $this->createChildUnit($unit, 'Cluster Unit 2', 36.670, 48.472);
        $this->createChildUnit($unit, 'Cluster Unit 3', 37.0, 49.0); // far away

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/clusters?zoom=10&bbox=48,36,49,37', $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type', 'features' => [
                    '*' => ['type', 'geometry', 'properties' => ['count']],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        // At least one cluster in the bbox (all parent units cluster together at low zoom)
        $this->assertGreaterThan(0, count($response->json('features')));
    }

    /** @test */
    public function test_gis_apis_respect_accessible_units(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createBBoxUser();
        $unit->update(['name' => 'Unit A', 'lat' => 36.669, 'lng' => 48.471]);
        $this->createChildUnit($unit, 'Unit A Child', 36.67, 48.48);

        // Create another unit not accessible to user
        $unitB = Unit::create(['name' => 'Unit B', 'lat' => 36.7, 'lng' => 48.5]);
        Person::factory()->create(['u_id' => $unitB->id]);
        Hardware::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'pc_name' => 'PC-B',
            'type' => 'laptop',
        ]);

        $token = $this->createApiToken($user, ['gis:read']);
        $response = $this->apiGet('/api/gis/units?bbox=48,36,49,37', $token);

        // Should only see Unit A and its child, not Unit B
        $features = $response->json('features');
        $this->assertCount(2, $response->json('features'));
        $names = array_column($features, 'properties.name');
        $this->assertNotContains('Unit B', $names);
    }

    /** @test */
    public function test_gis_cache_key_is_independent_of_accessible_ids_order(): void
    {
        $controller = $this->app->make(GisController::class);

        $method = new \ReflectionMethod($controller, 'gisCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($controller, 'units', [3, 1, 2], 'bbox');
        $key2 = $method->invoke($controller, 'units', [1, 2, 3], 'bbox');
        $key3 = $method->invoke($controller, 'units', [2, 3, 1], 'bbox');

        $this->assertSame($key1, $key2);
        $this->assertSame($key1, $key3);
    }

    /**
     * Every shape a client can get wrong: part count, non-numeric parts,
     * reversed axes, a degenerate box, and out-of-range coordinates.
     *
     * @return array<string, array{string}>
     */
    public static function malformedBboxSamples(): array
    {
        return [
            'three parts' => ['48,36,49'],
            'five parts' => ['48,36,49,37,5'],
            'non numeric' => ['abc,def,ghi,jkl'],
            'partially non numeric' => ['48,36,abc,37'],
            'reversed lon' => ['49,36,48,37'],
            'reversed lat' => ['48,37,49,36'],
            'degenerate' => ['48,36,48,37'],
            'out of range lon' => ['-181,36,49,37'],
            'out of range lat' => ['48,-91,49,37'],
        ];
    }

    /**
     * #1721: a malformed viewport used to be a silent 200 — full data for a
     * 3/5-part box, empty data for a non-numeric 4-part box. It must be a 422
     * on every GIS endpoint, not an ignored filter.
     */
    #[DataProvider('malformedBboxSamples')]
    public function test_every_gis_endpoint_rejects_a_malformed_bbox(string $bbox): void
    {
        ['user' => $user] = $this->createBBoxUser();
        $token = $this->createApiToken($user, ['gis:read']);

        foreach (['units', 'hardware', 'tickets', 'stats', 'clusters'] as $endpoint) {
            $this->apiGet("/api/gis/{$endpoint}?bbox=".urlencode($bbox), $token)
                ->assertStatus(422)
                ->assertJsonValidationErrors('bbox');
        }
    }

    /**
     * An array bbox used to reach `explode(',', array)` and 500 (#1721).
     *
     * @test
     */
    public function test_gis_rejects_an_array_bbox_instead_of_500(): void
    {
        ['user' => $user] = $this->createBBoxUser();
        $token = $this->createApiToken($user, ['gis:read']);

        $this->apiGet('/api/gis/units?bbox[]=1', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('bbox');
    }

    /**
     * An empty bbox means "no viewport", not "invalid viewport" (#1721).
     *
     * @test
     */
    public function test_gis_treats_an_empty_bbox_as_absent(): void
    {
        ['user' => $user] = $this->createBBoxUser();
        $token = $this->createApiToken($user, ['gis:read']);

        $this->apiGet('/api/gis/units?bbox=', $token)->assertStatus(200);
    }

    /**
     * A rejected viewport never reaches the cache, so it cannot write an
     * unfiltered payload under any key (#1721).
     *
     * @test
     */
    public function test_malformed_bbox_never_touches_the_cache(): void
    {
        ['user' => $user] = $this->createBBoxUser();
        $token = $this->createApiToken($user, ['gis:read']);

        Cache::spy();
        Cache::shouldReceive('remember')->never();

        $this->apiGet('/api/gis/units?bbox=48,36,49', $token)->assertStatus(422);
    }

    /**
     * The cache key format for a valid viewport is unchanged by #1721
     * (4 decimals, `all` only when the parameter is absent).
     *
     * @test
     */
    public function test_normalized_bbox_key_format_is_unchanged(): void
    {
        $controller = $this->app->make(GisController::class);

        $method = new \ReflectionMethod($controller, 'normalizedBbox');
        $method->setAccessible(true);

        $withBbox = UnitScopedRequest::create('/api/gis/units', 'GET', ['bbox' => '48.47163,36.669343,49,37']);
        $withoutBbox = UnitScopedRequest::create('/api/gis/units', 'GET');

        $this->assertSame('48.4716,36.6693,49,37', $method->invoke($controller, $withBbox));
        $this->assertSame('all', $method->invoke($controller, $withoutBbox));
    }
}
