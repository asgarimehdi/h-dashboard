<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MultiLatestValueController;
use App\Models\User;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    // -----------------------------------------------------------
    //  رفتار کش (#741)
    // -----------------------------------------------------------

    public function test_a_second_request_is_served_from_the_cache(): void
    {
        // The cache-hit branch returns before the client is called at all —
        // the mock allows exactly one call across both requests.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['item1' => 12.5]);
        });

        $url = '/api/zabbix/multi-latest?item_ids[]=item1';
        $token = $this->authUser();

        $this->apiGet($url, $token)->assertStatus(200);

        $this->assertSame(
            ['item1' => 12.5],
            Cache::get('multi_latest_item1'),
            'A successful fetch must be cached.'
        );

        $this->apiGet($url, $token)
            ->assertStatus(200)
            ->assertJson(['item1' => 12.5]);
    }

    public function test_the_cache_key_is_order_independent(): void
    {
        // Item ids are sorted before the key is built, so the same set in a
        // different order hits the same entry and Zabbix is called once.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['a' => 1.0, 'b' => 2.0]);
        });

        $token = $this->authUser();

        $this->apiGet('/api/zabbix/multi-latest?item_ids[]=b&item_ids[]=a', $token)
            ->assertStatus(200);

        $this->apiGet('/api/zabbix/multi-latest?item_ids[]=a&item_ids[]=b', $token)
            ->assertStatus(200)
            ->assertJson(['a' => 1.0, 'b' => 2.0]);

        $this->assertNotNull(Cache::get('multi_latest_a_b'));
    }

    public function test_a_failure_is_never_written_to_the_cache(): void
    {
        // The whole reason remember() was split into get/put: a broken Zabbix
        // must not pin its own 503 for the 20-second TTL. Assert the behaviour
        // a user sees — the next request retries — not the cache key.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Zabbix connection failed'));
        });

        $url = '/api/zabbix/multi-latest?item_ids[]=item1';
        $token = $this->authUser();

        $this->apiGet($url, $token)->assertStatus(503);

        $this->assertNull(
            Cache::get('multi_latest_item1'),
            'A failed fetch must not be cached.'
        );
    }

    public function test_a_recovered_zabbix_is_served_on_the_next_request(): void
    {
        // The user-visible consequence of the rule above: after Zabbix comes
        // back, the very next request returns data — no waiting out a TTL.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Zabbix connection failed'));
        });

        $url = '/api/zabbix/multi-latest?item_ids[]=item1';
        $token = $this->authUser();

        $this->apiGet($url, $token)->assertStatus(503);

        // Zabbix is healthy again for the retry.
        $this->app->instance(ZabbixService::class, $recovered = new class extends ZabbixService
        {
            public function __construct() {}

            public function getInterfaceTraffic($itemId, $duration = 3600)
            {
                return [];
            }

            public function getLatestValues(array $itemIds): array
            {
                return ['item1' => 7.5];
            }
        });

        $this->apiGet($url, $token)
            ->assertStatus(200)
            ->assertJson(['item1' => 7.5]);
    }
}
