<?php

namespace Tests\Feature;

use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Mockery;
use Tests\TestCase;

class TrafficApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
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

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->once()->with('100', 3600)->andReturn([['x' => 1, 'y' => 1.5]]);
        $mock->shouldReceive('getInterfaceTraffic')->once()->with('200', 3600)->andReturn([['x' => 1, 'y' => 2.3]]);
        $this->app->instance(ZabbixService::class, $mock);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200');

        $response->assertStatus(200)
            ->assertJsonStructure(['out', 'in']);
    }

    public function test_traffic_respects_duration_parameter(): void
    {
        $user = $this->createUser();

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->once()->with('100', 7200)->andReturn([]);
        $mock->shouldReceive('getInterfaceTraffic')->once()->with('200', 7200)->andReturn([]);
        $this->app->instance(ZabbixService::class, $mock);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200&duration=7200');

        $response->assertStatus(200);
    }

    public function test_traffic_caches_results(): void
    {
        $user = $this->createUser();

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->twice()->andReturn([['x' => 1, 'y' => 1.0]]);
        $this->app->instance(ZabbixService::class, $mock);

        $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200');
        $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/traffic?out_item_id=100&in_item_id=200');

        $this->assertNotEmpty(Cache::get('traffic_100_200_3600'));
    }

    protected function createUser(): \App\Models\User
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
        $nCode = (string) rand(1000000000, 9999999999);
        \App\Models\Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);
        return \App\Models\User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
    }
}
