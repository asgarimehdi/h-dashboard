<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiLatestValueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authUser()
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        return $this->actingAs($user, 'sanctum');
    }

    public function test_returns_latest_values_for_given_item_ids(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['item1' => 12.5, 'item2' => 8.0]);
        });

        $response = $this->authUser()
            ->getJson('/api/zabbix/multi-latest?item_ids[]=item1&item_ids[]=item2');

        $response->assertStatus(200)
            ->assertJson(['item1' => 12.5, 'item2' => 8.0]);
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

    public function test_returns_500_when_zabbix_fails(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Zabbix connection failed'));
        });

        $response = $this->authUser()
            ->getJson('/api/zabbix/multi-latest?item_ids[]=item1');

        $response->assertStatus(500)
            ->assertJsonStructure(['error', 'message'])
            ->assertJsonPath('error', 'Zabbix connection failed');
    }
}
