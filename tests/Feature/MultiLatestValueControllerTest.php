<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MultiLatestValueController;
use App\Models\User;
use App\Models\ZabbixDevice;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(MultiLatestValueController::class);

class MultiLatestValueControllerTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        // #1621: item ids are only accepted from the active-device allowlist.
        // These rows cover every id the tests below request.
        ZabbixDevice::factory()->create(['out_item_id' => '123']);
        ZabbixDevice::factory()->create(['out_item_id' => '12', 'in_item_id' => '34']);
        ZabbixDevice::factory()->create(['out_item_id' => '20', 'in_item_id' => '30']);
    }

    private function authUser(): string
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->givePermissionTo('map');

        return $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
    }

    public function test_returns_latest_values_for_given_item_ids(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['12' => 12.5, '34' => 8.0]);
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=12&item_ids[]=34',
            $this->authUser()
        );

        $response->assertStatus(200)
            ->assertJson(['12' => 12.5, '34' => 8.0]);
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

    public function test_rejects_a_non_numeric_item_id_before_touching_the_upstream(): void
    {
        // #1019 decision 1: item ids feed the cache key (`multi_latest_<ids>`
        // sorted), so they must be numeric strings — validation runs BEFORE
        // any cache/lock/upstream access.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')->never();
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=abc',
            $this->authUser()
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids.0']);
    }

    public function test_rejects_item_ids_exceeding_max_limit(): void
    {
        $itemIds = array_map('strval', range(1, 101));

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?'.http_build_query(['item_ids' => $itemIds]),
            $this->authUser()
        );

        $response->assertStatus(422);
    }

    public function test_rejects_item_id_exceeding_max_length(): void
    {
        // A numeric 65-character string passes the digits regex, so only the
        // max:64 rule rejects it — this is the length boundary, not a shape one.
        $longId = str_repeat('1', 65);

        $response = $this->apiGet(
            "/api/zabbix/multi-latest?item_ids[]={$longId}",
            $this->authUser()
        );

        $response->assertStatus(422);
    }

    public function test_accepts_exactly_100_item_ids(): void
    {
        // #1621: all 100 ids must be registered, so seed 50 devices covering
        // 1..100 before making the boundary request.
        $itemIds = array_map('strval', range(1, 100));
        foreach (array_chunk($itemIds, 2) as [$out, $in]) {
            ZabbixDevice::factory()->create(['out_item_id' => $out, 'in_item_id' => $in]);
        }

        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn([]);
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?'.http_build_query(['item_ids' => $itemIds]),
            $this->authUser()
        );

        $response->assertStatus(200);
    }

    public function test_rejects_a_list_containing_an_unregistered_item_id(): void
    {
        // #1621: fail-closed on the whole request — one unregistered id among
        // registered ones rejects everything, never a partial result.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')->never();
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=12&item_ids[]=34&item_ids[]=999',
            $this->authUser()
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids.2']);
    }

    public function test_rejects_a_list_of_only_unregistered_item_ids(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')->never();
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=900&item_ids[]=901',
            $this->authUser()
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids.0', 'item_ids.1']);
    }

    public function test_accepts_a_registered_only_list_and_calls_the_upstream_once(): void
    {
        // The allowlist must not change the upstream call shape for registered
        // ids: one batched call with the sorted ids, exactly as before.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->with(['12', '34'])
                ->andReturn(['12' => 12.5, '34' => 8.0]);
        });

        $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=12&item_ids[]=34',
            $this->authUser()
        )
            ->assertStatus(200)
            ->assertJson(['12' => 12.5, '34' => 8.0]);
    }

    public function test_rejects_every_item_id_when_the_registry_is_empty(): void
    {
        // `Rule::in([])` always fails: an empty registry is fail-closed, never
        // "no allowlist configured, accept anything".
        ZabbixDevice::query()->delete();

        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')->never();
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=12',
            $this->authUser()
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_ids.0']);
    }

    public function test_returns_500_when_zabbix_fails(): void
    {
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Zabbix connection failed'));
        });

        $response = $this->apiGet(
            '/api/zabbix/multi-latest?item_ids[]=123',
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
        // the mock allows exactly one call across both requests, and the
        // literal `multi_latest_<sorted numeric ids>` key format stays as
        // pinned by #1019 decision 4.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['123' => 12.5]);
        });

        $url = '/api/zabbix/multi-latest?item_ids[]=123';
        $token = $this->authUser();

        $this->apiGet($url, $token)->assertStatus(200);

        $this->assertSame(
            ['123' => 12.5],
            Cache::get('multi_latest_123'),
            'A successful fetch must be cached.'
        );

        $this->apiGet($url, $token)
            ->assertStatus(200)
            ->assertJson(['123' => 12.5]);
    }

    public function test_the_cache_key_is_order_independent(): void
    {
        // Item ids are sorted before the key is built, so the same set in a
        // different order hits the same entry and Zabbix is called once.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andReturn(['20' => 1.0, '30' => 2.0]);
        });

        $token = $this->authUser();

        $this->apiGet('/api/zabbix/multi-latest?item_ids[]=30&item_ids[]=20', $token)
            ->assertStatus(200);

        $this->apiGet('/api/zabbix/multi-latest?item_ids[]=20&item_ids[]=30', $token)
            ->assertStatus(200)
            ->assertJson(['20' => 1.0, '30' => 2.0]);

        $this->assertNotNull(Cache::get('multi_latest_20_30'));
    }

    public function test_a_failure_is_never_written_to_the_cache(): void
    {
        // The whole reason remember() was split into get/put: a broken Zabbix
        // must not pin its own 503 for the TTL. Assert the behaviour a user
        // sees — the next request retries — not the cache key.
        $this->mock(ZabbixService::class, function ($mock) {
            $mock->shouldReceive('getLatestValues')
                ->once()
                ->andThrow(new \Exception('Zabbix connection failed'));
        });

        $url = '/api/zabbix/multi-latest?item_ids[]=123';
        $token = $this->authUser();

        $this->apiGet($url, $token)->assertStatus(503);

        $this->assertNull(
            Cache::get('multi_latest_123'),
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

        $url = '/api/zabbix/multi-latest?item_ids[]=123';
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
                return ['123' => 7.5];
            }
        });

        $this->apiGet($url, $token)
            ->assertStatus(200)
            ->assertJson(['123' => 7.5]);
    }
}
