<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GisController;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(GisController::class);

class GisApiTest extends TestCase
{
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->createChildUnit($unit, 'Child Unit', 36.7, 48.5);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/units?bbox=48,36,49,37');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $unit->update(['lat' => 36.669343, 'lng' => 48.47163]);
        $this->createChildUnit($unit, 'Child In BBox', 36.67, 48.48);
        $this->createChildUnit($unit, 'Child Out BBox', 38.0, 50.0); // clearly outside

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/units?bbox=48,36,49,37');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $person = Person::where('u_id', $unit->id)->first();

        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'laptop',
            'cpu' => 'Intel i5',
            'ram' => '8GB',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/hardware?bbox=48,36,49,37');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
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

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/hardware?bbox=48,36,49,37&type=laptop');

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('laptop', $response->json('features.0.properties.type'));
    }

    /** @test */
    public function test_gis_tickets_returns_geojson_feature_collection(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'Test Ticket',
            'content' => 'Test content',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/tickets?bbox=48,36,49,37');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);

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

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/tickets?bbox=48,36,49,37&priority=urgent');

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('urgent', $response->json('features.0.properties.priority'));

        // Test status filter
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/tickets?bbox=48,36,49,37&status=completed');

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('completed', $response->json('features.0.properties.status'));
    }

    /** @test */
    public function test_gis_stats_returns_counts(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
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

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/stats?bbox=48,36,49,37');

        $response->assertStatus(200)
            ->assertJsonStructure(['units', 'hardware', 'open_tickets']);

        $this->assertEquals(2, $response->json('units'));
        $this->assertEquals(1, $response->json('hardware'));
        $this->assertEquals(1, $response->json('open_tickets')); // only non-completed
    }

    /** @test */
    public function test_gis_clusters_returns_clustered_data(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->createChildUnit($unit, 'Cluster Unit 1', 36.669, 48.471);
        $this->createChildUnit($unit, 'Cluster Unit 2', 36.670, 48.472);
        $this->createChildUnit($unit, 'Cluster Unit 3', 37.0, 49.0); // far away

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/clusters?zoom=10&bbox=48,36,49,37');

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
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

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/gis/units?bbox=48,36,49,37');

        // Should only see Unit A and its child, not Unit B
        $features = $response->json('features');
        $this->assertCount(2, $response->json('features'));
        $names = array_column($features, 'properties.name');
        $this->assertNotContains('Unit B', $names);
    }
}
