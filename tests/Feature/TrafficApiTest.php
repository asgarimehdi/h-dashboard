<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TrafficController;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Mockery;
use RuntimeException;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TrafficController::class);

class TrafficApiTest extends TestCase
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

        // Bind a mock ZabbixService up-front so the TrafficController (resolved
        // during the first request of any test) receives the mocked instance
        // rather than the real service. Individual tests may re-bind if needed.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->andReturn([['x' => 1, 'y' => 1.5]]);
        $this->app->instance(ZabbixService::class, $mock);
    }

    protected function tearDown(): void
    {
        // Parent first: the RefreshDatabase rollback must not be skipped when
        // Mockery::close() reports an unmet expectation — an open test
        // transaction deadlocks the next test's database preparation.
        parent::tearDown();
        Mockery::close();
    }

    public function test_unauthenticated_user_cannot_access_traffic(): void
    {
        $response = $this->getJson('/api/zabbix/traffic?out_item_id=1&in_item_id=2');

        $response->assertStatus(401);
    }

    public function test_traffic_requires_out_item_id(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $response = $this->apiGet('/api/zabbix/traffic?in_item_id=2', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['out_item_id']);
    }

    public function test_traffic_requires_in_item_id(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=1', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['in_item_id']);
    }

    public function test_traffic_returns_out_and_in_data(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['out', 'in']);
    }

    public function test_traffic_respects_duration_parameter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200&duration=7200', $token);

        $response->assertStatus(200);
    }

    public function test_traffic_caches_results(): void
    {
        // #1019: the second identical request must be a cache hit — exactly
        // one upstream call per side across BOTH requests, and the literal
        // `traffic_<out>_<in>_<duration>` key format the widget (#764) and
        // this assertion pin stays unchanged.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('100', 3600)
            ->andReturn([['x' => 1, 'y' => 1.5]]);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('200', 3600)
            ->andReturn([['x' => 1, 'y' => 2.5]]);
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(200)
            ->assertJsonPath('out.0.y', 1.5)
            ->assertJsonPath('in.0.y', 2.5);

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(200)
            ->assertJsonPath('out.0.y', 1.5)
            ->assertJsonPath('in.0.y', 2.5);

        $this->assertNotEmpty(Cache::get('traffic_100_200_3600'));
    }

    public function test_traffic_rejects_a_non_numeric_item_id_before_touching_the_upstream(): void
    {
        // #1019 decision 1: item ids feed the cache key, so they must be numeric
        // strings — validation runs BEFORE any cache/lock/upstream access.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->never();
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=abc&in_item_id=200', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['out_item_id']);
    }

    public function test_traffic_rejects_an_oversized_item_id_before_touching_the_upstream(): void
    {
        // 40 characters is longer than the max:32 rule; it is a numeric string,
        // so only the length rule rejects it.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->never();
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $oversizedId = str_repeat('1', 40);

        $response = $this->apiGet("/api/zabbix/traffic?out_item_id={$oversizedId}&in_item_id=200", $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['out_item_id']);
    }

    public function test_the_miss_path_reopens_after_a_real_upstream_failure(): void
    {
        // #1019 decision 2 (flexible): after a REAL upstream failure — the
        // genuine, unchanged 503 — nothing is cached and no refresh state is
        // left behind, so the very next request is served fresh. One chained
        // mock makes both phases deterministic: the first call ('out') throws,
        // the second returns data, and the 'in' side is only reached on the
        // recovered phase. A leaked lock or a cached failure would turn this
        // red.
        $outCalls = 0;
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')
            ->twice()
            ->with('100', 3600)
            ->andReturnUsing(function () use (&$outCalls) {
                return (++$outCalls === 1)
                    ? throw new RuntimeException('cURL error 28: Operation timed out')
                    : [['x' => 1, 'y' => 1.5]];
            });
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('200', 3600)
            ->andReturn([['x' => 1, 'y' => 2.5]]);
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;
        $url = '/api/zabbix/traffic?out_item_id=100&in_item_id=200';

        $this->apiGet($url, $token)
            ->assertStatus(503)
            ->assertJsonPath('error', 'Service temporarily unavailable');

        $this->assertNull(
            Cache::get('traffic_100_200_3600'),
            'The failed miss must not leave anything in the cache.'
        );

        $this->apiGet($url, $token)
            ->assertStatus(200)
            ->assertJsonPath('out.0.y', 1.5)
            ->assertJsonPath('in.0.y', 2.5);
    }

    public function test_a_contested_refresh_serves_the_stale_value_quietly(): void
    {
        // #1019 decision 2: while a refresh for this key is already in flight
        // (the flexible refresh lock is held), an arriving request gets the
        // stale value instead of an error — no upstream call and no error-level
        // logging, so a contented refresh never becomes Sentry noise.
        Cache::flexible(
            'traffic_100_250_7200',
            [240, 300],
            fn () => ['out' => [['x' => 1, 'y' => 1.25]], 'in' => [['x' => 1, 'y' => 2.75]]]
        );

        // Age the entry past the 240-second fresh window (still inside the
        // 300-second TTL) so the request reaches the stale/refresh branch.
        $this->travel(255)->seconds();

        $lock = Cache::lock('illuminate:cache:flexible:lock:traffic_100_250_7200', 10);
        $this->assertTrue($lock->get());

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->never();
        $this->app->instance(ZabbixService::class, $mock);

        Log::spy();

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        try {
            $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=250&duration=7200', $token)
                ->assertStatus(200)
                ->assertJsonPath('out.0.y', 1.25)
                ->assertJsonPath('in.0.y', 2.75);
        } finally {
            $lock->release();
        }

        Log::assertNothingLogged();
    }

    // -----------------------------------------------------------
    //  مسیرهای خطا (#741)
    // -----------------------------------------------------------

    public function test_a_failure_on_the_out_side_answers_503_without_touching_the_in_side(): void
    {
        // The two sides are fetched in sequence, so a failing `out` must stop
        // the request before the `in` call — a 503 with the same body either
        // way, but only one Zabbix call was made.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('100', 3600)
            ->andThrow(new RuntimeException('cURL error 28: Operation timed out'));
        $mock->shouldReceive('getInterfaceTraffic')->never();
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token);

        $response->assertStatus(503)
            ->assertJsonPath('error', 'Service temporarily unavailable');
    }

    public function test_a_failure_on_the_in_side_answers_503(): void
    {
        // The out side already succeeded; the in side is the one that breaks.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('100', 3600)
            ->andReturn([['x' => 1, 'y' => 1.5]]);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('200', 3600)
            ->andThrow(new RuntimeException('Zabbix API HTTP error: 503'));
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(503)
            ->assertJsonPath('error', 'Service temporarily unavailable');
    }

    public function test_a_failure_is_never_written_to_the_cache(): void
    {
        // #741: same rule as multi-latest — a broken Zabbix must not pin its
        // own error for the TTL, so a later healthy request is served fresh.
        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('100', 3600)
            ->andThrow(new RuntimeException('Zabbix API returned invalid JSON'));
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(503);

        $this->assertNull(
            Cache::get('traffic_100_200_3600'),
            'A failed sync must not be cached.'
        );
    }

    // -----------------------------------------------------------
    //  ناسازگاری کلید کش (#764)
    // -----------------------------------------------------------

    public function test_traffic_ignores_the_sync_job_cache_for_other_item_ids(): void
    {
        // #764: SyncZabbixJob warms `zabbix_traffic_data` with the CONFIGURED
        // default items every 5 minutes (the dashboard banner also reads it
        // via Cache::has). A request for different item IDs must be served
        // its own items' data — the job's cache must not short-circuit it,
        // and the response keeps the `out`/`in` shape the chart widget reads.
        Cache::put('zabbix_traffic_data', [['x' => 1, 'y' => 9.9]], now()->addMinutes(5));

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('100', 3600)
            ->andReturn([['x' => 1, 'y' => 1.5]]);
        $mock->shouldReceive('getInterfaceTraffic')
            ->once()
            ->with('200', 3600)
            ->andReturn([['x' => 1, 'y' => 2.5]]);
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(200)
            ->assertJsonPath('out.0.y', 1.5)
            ->assertJsonPath('in.0.y', 2.5)
            ->assertJsonMissingPath('data');
    }

    public function test_traffic_reads_back_its_own_per_request_cache(): void
    {
        // #764: the dynamic `traffic_{out}_{in}_{duration}` key used to be a
        // dead write — written on every fresh fetch, read by nothing. It is
        // now a read-through cache: a warm key serves the request without
        // touching Zabbix. Warmed through the same flexible API the controller
        // caches with (so the created-marker exists under the value).
        Cache::flexible(
            'traffic_100_200_3600',
            [240, 300],
            fn () => ['out' => [['x' => 1, 'y' => 3.5]], 'in' => [['x' => 1, 'y' => 4.5]]]
        );

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->never();
        $this->app->instance(ZabbixService::class, $mock);

        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('test-token', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(200)
            ->assertJsonPath('out.0.y', 3.5)
            ->assertJsonPath('in.0.y', 4.5);
    }
}
