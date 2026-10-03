<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TrafficController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\ZabbixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Mockery;
use RuntimeException;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

covers(TrafficController::class);

class TrafficApiTest extends TestCase
{
    use InteractsWithApiTokens;
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
        $token = $this->createApiToken($user, ['traffic:read']);
        $response = $this->apiGet('/api/zabbix/traffic?in_item_id=2', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['out_item_id']);
    }

    public function test_traffic_requires_in_item_id(): void
    {
        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);
        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=1', $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['in_item_id']);
    }

    public function test_traffic_returns_out_and_in_data(): void
    {
        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['out', 'in']);
    }

    public function test_traffic_respects_duration_parameter(): void
    {
        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

        $response = $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200&duration=7200', $token);

        $response->assertStatus(200);
    }

    public function test_traffic_caches_results(): void
    {
        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);
        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token);
        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token);

        $this->assertNotEmpty(Cache::get('traffic_100_200_3600'));
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

        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

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

        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

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

        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

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

        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

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
        // now a real read-through cache: a warm key serves the request
        // without touching Zabbix.
        Cache::put(
            'traffic_100_200_3600',
            ['out' => [['x' => 1, 'y' => 3.5]], 'in' => [['x' => 1, 'y' => 4.5]]],
            30
        );

        $mock = Mockery::mock(ZabbixService::class);
        $mock->shouldReceive('getInterfaceTraffic')->never();
        $this->app->instance(ZabbixService::class, $mock);

        $user = $this->createUser();
        $token = $this->createApiToken($user, ['traffic:read']);

        $this->apiGet('/api/zabbix/traffic?out_item_id=100&in_item_id=200', $token)
            ->assertStatus(200)
            ->assertJsonPath('out.0.y', 3.5)
            ->assertJsonPath('in.0.y', 4.5);
    }

    protected function createUser(): User
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);

        return User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
    }
}
