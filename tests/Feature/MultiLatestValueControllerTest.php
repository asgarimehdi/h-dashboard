<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MultiLatestValueController;
use App\Models\User;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

covers(MultiLatestValueController::class);

class MultiLatestValueControllerTest extends TestCase
{
    use InteractsWithApiTokens;
    use RefreshDatabase;

    private function authUser(): string
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        return $this->createApiToken($user, ['traffic:read']);
    }

    public function test_returns_latest_values_for_given_item_ids(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['item1' => 12.5, 'item2' => 8.0]);
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=item1&item_ids[]=item2',
            $this->authUser()
        );

        $response->assertStatus(200)
            ->assertJson(['item1' => 12.5, 'item2' => 8.0]);
    }

    public function test_validates_item_ids_is_required_array(): void
    {
        $response = $this->apiGet('/api/zabbix/multi-latest', $this->authUser());

        $response->assertStatus(422);
    }

    public function test_validates_item_ids_entries_are_strings(): void
    {
        // Passing a non-array value for item_ids should fail the 'array' rule.
        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids=not-an-array',
            $this->authUser()
        );

        $response->assertStatus(422);
    }

    public function test_rejects_item_ids_exceeding_max_limit(): void
    {
        $itemIds = array_map(fn ($i) => "item{$i}", range(1, 101));

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?'.http_build_query(['item_ids' => $itemIds]),
            $this->authUser()
        );

        $response->assertStatus(422);
    }

    public function test_rejects_item_id_exceeding_max_length(): void
    {
        $longId = str_repeat('a', 65);

        $response = $this->apiGet(
            "/api/zabbix/multi-latest?item_ids[]={$longId}",
            $this->authUser()
        );

        $response->assertStatus(422);
    }

    public function test_accepts_exactly_100_item_ids(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn([]);
        });

        $itemIds = array_map(fn ($i) => "item{$i}", range(1, 100));

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?'.http_build_query(['item_ids' => $itemIds]),
            $this->authUser()
        );

        $response->assertStatus(200);
    }

    public function test_returns_500_when_zabbix_fails(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Zabbix connection failed'));
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=item1',
            $this->authUser()
        );

        $response->assertStatus(503)
            ->assertJsonPath('error', 'Service temporarily unavailable');
    }
}
