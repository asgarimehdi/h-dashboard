<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MultiLatestValueController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Mockery;
use Tests\TestCase;

covers(MultiLatestValueController::class);

class MultiLatestValueApiTest extends TestCase
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

    public function test_unauthenticated_user_cannot_access_multi_latest(): void
    {
        $response = $this->getJson('/api/zabbix/multi-latest?item_ids[]=1');

        $response->assertStatus(401);
    }

    public function test_multi_latest_requires_item_ids(): void
    {
        $user = $this->createUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/multi-latest');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids']);
    }

    public function test_multi_latest_requires_item_ids_to_be_array(): void
    {
        $user = $this->createUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/multi-latest?item_ids=notanarray');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids']);
    }

    public function test_multi_latest_returns_values(): void
    {
        $user = $this->createUser();

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getLatestValues')->once()->with(['100', '200'])->andReturn([
            '100' => 1.5,
            '200' => 2.3,
        ]);
        $this->app->instance(ZabbixService::class, $mock);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/multi-latest?item_ids[]=100&item_ids[]=200');

        $response->assertStatus(200)
            ->assertJson(['100' => 1.5, '200' => 2.3]);
    }

    public function test_multi_latest_returns_null_for_missing_items(): void
    {
        $user = $this->createUser();

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getLatestValues')->once()->with(['999'])->andReturn(['999' => null]);
        $this->app->instance(ZabbixService::class, $mock);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/zabbix/multi-latest?item_ids[]=999');

        $response->assertStatus(200)
            ->assertJson(['999' => null]);
    }

    protected function createUser(): User
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
        $nCode = (string) fake()->unique()->numerify('##########');
        $unit = Unit::create(['name' => 'Test Unit']);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);

        return User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
    }
}
