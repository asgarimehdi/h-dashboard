<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MultiLatestValueController;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Mockery;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(MultiLatestValueController::class);

class MultiLatestValueApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
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
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $response = $this->apiGet('/api/zabbix/multi-latest', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids']);
    }

    public function test_multi_latest_requires_item_ids_to_be_array(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $response = $this->apiGet('/api/zabbix/multi-latest?item_ids=notanarray', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids']);
    }

    public function test_multi_latest_returns_values(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getLatestValues')->once()->with(['100', '200'])->andReturn([
            '100' => 1.5,
            '200' => 2.3,
        ]);
        $this->app->instance(ZabbixService::class, $mock);

        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $response = $this->apiGet('/api/zabbix/multi-latest?item_ids[]=100&item_ids[]=200', $token);

        $response->assertStatus(200)
            ->assertJson(['100' => 1.5, '200' => 2.3]);
    }

    public function test_multi_latest_returns_null_for_missing_items(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getLatestValues')->once()->with(['999'])->andReturn(['999' => null]);
        $this->app->instance(ZabbixService::class, $mock);

        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $response = $this->apiGet('/api/zabbix/multi-latest?item_ids[]=999', $token);

        $response->assertStatus(200)
            ->assertJson(['999' => null]);
    }
}
