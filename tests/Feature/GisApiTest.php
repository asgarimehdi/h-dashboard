<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

covers(\App\Http\Controllers\Api\GisController::class);

class GisApiTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;

    protected $eId;

    protected $sId;

    protected $rId;

    protected $unit;

    protected $unitChild;

    protected $user;

    protected $person;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        // Setup reference data
        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'Test Tahsil']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'Test Estekhdam']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'Test Semat']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'Test Radif']);
    }

    protected function createUserWithUnit(array $unitData = []): array
    {
        $this->unit = Unit::create(array_merge([
            'name' => 'Test Unit',
            'lat' => 36.669343,
            'lng' => 48.47163,
        ], $unitData));

        $nCode = (string) fake()->unique()->numerify('##########');

        $this->person = Person::create([
            'n_code' => $nCode,
            'f_name' => 'Test',
            'l_name' => 'User',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);

        $this->user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);

        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->seed(PermissionSeeder::class);
        $this->user->givePermissionTo('map');

        return ['user' => $this->user, 'unit' => $this->unit];
    }

    protected function createChildUnit(string $name, float $lat, float $lng): Unit
    {
        return Unit::create([
            'name' => $name,
            'parent_id' => $this->unit->id,
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
        $this->createUserWithUnit();
        $this->createChildUnit('Child Unit', 36.7, 48.5);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/units?bbox=48,36,49,37');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'features' => [
                    '*' => ['type', 'id', 'geometry', 'properties'],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(2, $response->json('features'));
    }

    /** @test */
    public function test_gis_units_filters_by_bbox(): void
    {
        $this->createUserWithUnit(['lat' => 36.669343, 'lng' => 48.47163]);
        $this->createChildUnit('Child In BBox', 36.67, 48.48);
        $this->createChildUnit('Child Out BBox', 38.0, 50.0); // clearly outside

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/units?bbox=48,36,49,37');

        // Parent unit + Child In BBox = 2; Child Out BBox filtered out
        $features = $response->json('features');
        $this->assertCount(2, $features);
        $names = array_column(array_column($features, 'properties'), 'name');
        $this->assertContains('Test Unit', $names);
        $this->assertContains('Child In BBox', $names);
    }

    /** @test */
    public function test_gis_hardware_returns_geojson_feature_collection(): void
    {
        $this->createUserWithUnit();
        $person = Person::first();

        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'laptop',
            'cpu' => 'Intel i5',
            'ram' => '8GB',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/hardware?bbox=48,36,49,37');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'features' => [
                    '*' => ['type', 'id', 'geometry', 'properties'],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
    }

    /** @test */
    public function test_gis_hardware_filters_by_type(): void
    {
        $this->createUserWithUnit();
        $person = Person::first();

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

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/hardware?bbox=48,36,49,37&type=laptop');

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('laptop', $response->json('features.0.properties.type'));
    }

    /** @test */
    public function test_gis_tickets_returns_geojson_feature_collection(): void
    {
        $this->createUserWithUnit();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'subject' => 'Test Ticket',
            'content' => 'Test content',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/tickets?bbox=48,36,49,37');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'features' => [
                    '*' => ['type', 'id', 'geometry', 'properties'],
                ],
            ]);

        $this->assertEquals('FeatureCollection', $response->json('type'));
        $this->assertCount(1, $response->json('features'));
    }

    /** @test */
    public function test_gis_tickets_filters_by_priority_and_status(): void
    {
        $this->createUserWithUnit();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'subject' => 'Urgent Ticket',
            'content' => 'Test',
            'priority' => 'urgent',
            'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-002',
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'subject' => 'Normal Ticket',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-003',
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'subject' => 'Completed Ticket',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/tickets?bbox=48,36,49,37&priority=urgent');

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('urgent', $response->json('features.0.properties.priority'));

        // Test status filter
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/tickets?bbox=48,36,49,37&status=completed');

        $this->assertCount(1, $response->json('features'));
        $this->assertEquals('completed', $response->json('features.0.properties.status'));
    }

    /** @test */
    public function test_gis_stats_returns_counts(): void
    {
        $this->createUserWithUnit();
        $this->createChildUnit('Child Unit', 36.67, 48.48);
        $person = Person::first();

        Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'laptop',
        ]);

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'subject' => 'Test',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-002',
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'subject' => 'Test 2',
            'content' => 'Test',
            'priority' => 'normal',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
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
        $this->createUserWithUnit();
        $this->createChildUnit('Cluster Unit 1', 36.669, 48.471);
        $this->createChildUnit('Cluster Unit 2', 36.670, 48.472);
        $this->createChildUnit('Cluster Unit 3', 37.0, 49.0); // far away

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/clusters?zoom=10&bbox=48,36,49,37');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'features' => [
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
        $this->createUserWithUnit(['name' => 'Unit A', 'lat' => 36.669, 'lng' => 48.471]);
        $this->createChildUnit('Unit A Child', 36.67, 48.48);

        // Create another unit not accessible to user
        $unitB = Unit::create(['name' => 'Unit B', 'lat' => 36.7, 'lng' => 48.5]);
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2,
            'f_name' => 'Other',
            'l_name' => 'User',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $unitB->id,
        ]);
        Hardware::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'pc_name' => 'PC-B',
            'type' => 'laptop',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/gis/units?bbox=48,36,49,37');

        // Should only see Unit A and its child, not Unit B
        $features = $response->json('features');
        $this->assertCount(2, $response->json('features'));
        $names = array_column($features, 'properties.name');
        $this->assertNotContains('Unit B', $names);
    }
}
