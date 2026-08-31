<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Mockery;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\Api\TrafficController::class)]

class TrafficApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        // Bind a mock ZabbixService up-front so the TrafficController (resolved
        // during the first request of any test) receives the mocked instance
        // rather than the real service. Individual tests may re-bind if needed.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->andReturn([['x' => 1, 'y' => 1.5]]);
        $this->app->instance(ZabbixService::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_access_traffic(): void
    {
        $response = $this->getJson('/api/zabbix/traffic?out_item_id=1&in_item_id=2');

        $response->assertStatus(401);
    }

    public function test_traffic_requires_out_item_id(): void
    {
        $user = $this->createUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?in_item_id=2');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['out_item_id']);
    }

    public function test_traffic_requires_in_item_id(): void
    {
        $user = $this->createUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=1');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['in_item_id']);
    }

    public function test_traffic_returns_out_and_in_data(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200');

        $response->assertStatus(200)
            ->assertJsonStructure(['out', 'in']);
    }

    public function test_traffic_respects_duration_parameter(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200&duration=7200');

        $response->assertStatus(200);
    }

    public function test_traffic_caches_results(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200');
        $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200');

        $this->assertNotEmpty(Cache::get('traffic_100_200_3600'));
    }

    protected function createUser(): User
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
        $unit = \App\Models\Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);

        return User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
    }
}
