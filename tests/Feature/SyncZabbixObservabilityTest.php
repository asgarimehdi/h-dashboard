<?php

namespace Tests\Feature;

use App\Jobs\SyncZabbixJob;
use App\Models\Notification;
use App\Models\ZabbixSyncLog;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Mockery;
use RuntimeException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(SyncZabbixJob::class);

/**
 * #740 — the sync job's failures must be visible without reading logs:
 * a persistent row per run, a dashboard banner that tells healthy sync
 * apart from stale cache, and one in-app admin alert at three consecutive
 * failures.
 */
class SyncZabbixObservabilityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        // The real service casts config to string properties; give it values
        // so constructing it never TypeErrors, even where it is never used.
        config([
            'services.zabbix.url' => 'http://zabbix.test/api_jsonrpc.php',
            'services.zabbix.token' => 'test-token',
            'services.zabbix.out_item_id' => '100',
            'services.zabbix.in_item_id' => '200',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------
    //  رکورد هر اجرا
    // -----------------------------------------------------------

    public function test_successful_run_records_a_success_row(): void
    {
        $zabbix = Mockery::mock(ZabbixService::class);
        $zabbix->shouldReceive('getInterfaceTraffic')->andReturn([])->twice();

        (new SyncZabbixJob)->handle($zabbix);

        $this->assertDatabaseHas('zabbix_sync_logs', [
            'success' => true,
            'consecutive_failures' => 0,
        ]);
        $this->assertNotNull(ZabbixSyncLog::first()?->ran_at);
    }

    public function test_unconfigured_skip_records_a_failure_row(): void
    {
        // Used to be a warning nobody read — now a visible failed run (#740).
        config(['services.zabbix.out_item_id' => null]);

        (new SyncZabbixJob)->handle(Mockery::mock(ZabbixService::class));

        $log = ZabbixSyncLog::first();
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertSame(1, $log->consecutive_failures);
        $this->assertStringContainsString('not configured', (string) $log->error);
    }

    public function test_terminal_failure_records_a_failure_row(): void
    {
        (new SyncZabbixJob)->failed(new RuntimeException('connection refused'));

        $log = ZabbixSyncLog::first();
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertSame(1, $log->consecutive_failures);
        $this->assertSame('connection refused', $log->error);
    }

    public function test_a_success_resets_the_streak(): void
    {
        $job = new SyncZabbixJob;
        $job->failed(new RuntimeException('boom'));
        $job->failed(new RuntimeException('boom again'));

        $zabbix = Mockery::mock(ZabbixService::class);
        $zabbix->shouldReceive('getInterfaceTraffic')->andReturn([])->twice();
        $job->handle($zabbix);

        $job->failed(new RuntimeException('boom again'));

        $this->assertSame(1, ZabbixSyncLog::query()->latest('id')->first()?->consecutive_failures);
    }

    // -----------------------------------------------------------
    //  هشدار admin بعد از ۳ فیل متوالی
    // -----------------------------------------------------------

    public function test_three_consecutive_failures_notify_admins_once(): void
    {
        ['user' => $admin] = $this->createUserWithUnit(['kargozini']);
        $admin->assignRole('admin');

        $job = new SyncZabbixJob;
        $job->failed(new RuntimeException('boom 1'));
        $job->failed(new RuntimeException('boom 2'));

        // Not before the threshold.
        $this->assertSame(0, $this->notificationsOfType('zabbix_sync')->count());

        $job->failed(new RuntimeException('boom 3'));

        $adminNotifications = $this->notificationsOfType('zabbix_sync')
            ->where('user_id', $admin->id)
            ->count();
        $this->assertSame(1, $adminNotifications);

        // A fourth failure does not spam a second alert.
        $job->failed(new RuntimeException('boom 4'));
        $this->assertSame(
            1,
            $this->notificationsOfType('zabbix_sync')->where('user_id', $admin->id)->count()
        );
    }

    public function test_non_admins_receive_no_alert(): void
    {
        ['user' => $member] = $this->createUserWithUnit(['kargozini']);

        $job = new SyncZabbixJob;
        $job->failed(new RuntimeException('boom 1'));
        $job->failed(new RuntimeException('boom 2'));
        $job->failed(new RuntimeException('boom 3'));

        $this->assertSame(0, $this->notificationsOfType('zabbix_sync')->where('user_id', $member->id)->count());
    }

    // -----------------------------------------------------------
    //  ویجت داشبورد
    // -----------------------------------------------------------

    public function test_dashboard_shows_a_healthy_sync(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        Cache::put('zabbix_traffic_data', [['x' => 1, 'y' => 1.0]], now()->addMinutes(5));

        ZabbixSyncLog::create(['success' => true, 'consecutive_failures' => 0, 'ran_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('sync زیبکس سالم');
    }

    public function test_dashboard_tells_a_single_failure_apart_and_flags_stale_cache(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        // The cache still serves old numbers while sync is broken — the
        // banner must say so instead of looking healthy (#740).
        Cache::put('zabbix_traffic_data', [['x' => 1, 'y' => 1.0]], now()->addMinutes(5));

        ZabbixSyncLog::create([
            'success' => false,
            'consecutive_failures' => 1,
            'error' => 'connection refused',
            'ran_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('sync زیبکس ناموفق')
            ->assertSee('نمایش از کش قدیمی')
            ->assertDontSee('sync زیبکس سالم');
    }

    public function test_dashboard_flags_a_stopped_scheduler_as_stale(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        // Last run succeeded, but half an hour ago — the queue/scheduler
        // stopped running, which "success" alone would hide forever.
        ZabbixSyncLog::create([
            'success' => true,
            'consecutive_failures' => 0,
            'ran_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('آخرین sync زیبکس قدیمی است')
            ->assertDontSee('sync زیبکس سالم');
    }

    public function test_dashboard_hides_the_banner_before_the_first_run(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('sync زیبکس');
    }

    // -----------------------------------------------------------
    //  helpers
    // -----------------------------------------------------------

    /**
     * Notifications written by NotificationService for the given type.
     *
     * @return Builder
     */
    protected function notificationsOfType(string $type)
    {
        return Notification::query()->where('type', $type);
    }
}
