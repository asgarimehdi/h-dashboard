<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MultiLatestValueController;
use App\Models\User;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

covers(MultiLatestValueController::class);

class MultiLatestValueControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function authUser()
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->givePermissionTo('bw');

        return $this->actingAs($user, 'sanctum');
    }

    public function test_returns_latest_values_for_given_item_ids(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->with(['1', '2'])
                ->andReturn(['1' => 12.5, '2' => 8.0]);
        });

        $response = $this->authUser()
            ->getJson('/api/zabbix/multi-latest?item_ids[]=1&item_ids[]=2');

        $response->assertStatus(200)
            ->assertJson(['1' => 12.5, '2' => 8.0]);
    }

    public function test_validates_item_ids_is_required_array(): void
    {
        $response = $this->authUser()->getJson('/api/zabbix/multi-latest');

        $response->assertStatus(422);
    }

    public function test_validates_item_ids_entries_are_strings(): void
    {
        // Passing a non-array value for item_ids should fail the 'array' rule.
        $response = $this->authUser()
            ->getJson('/api/zabbix/multi-latest?item_ids=not-an-array');

        $response->assertStatus(422);
    }

    public function test_rejects_non_integer_item_id_entries(): void
    {
        $response = $this->authUser()
            ->getJson('/api/zabbix/multi-latest?item_ids[]=abc');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids.0']);
    }

    public function test_returns_500_with_generic_message_when_zabbix_fails(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Internal secret connection string'));
        });

        $response = $this->authUser()
            ->getJson('/api/zabbix/multi-latest?item_ids[]=1');

        $response->assertStatus(500)
            ->assertJsonStructure(['error', 'message'])
            ->assertJsonPath('error', 'Zabbix connection failed')
            ->assertJsonPath('message', 'Zabbix unavailable');

        // The raw exception text must NOT leak to the client.
        $this->assertStringNotContainsString('Internal secret', $response->getContent());
    }

    public function test_user_without_bw_permission_gets_403(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/zabbix/multi-latest?item_ids[]=1');

        $response->assertStatus(403);
    }
}
