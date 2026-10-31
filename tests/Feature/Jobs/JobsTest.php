<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveActivityLogsJob;
use App\Jobs\CleanNotificationsJob;
use App\Jobs\GenerateDailyReportsJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SyncZabbixJob;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class JobsTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ── ArchiveActivityLogsJob ────────────────────────────────────────

    public function test_archive_activity_logs_job_is_dispatched(): void
    {
        Queue::fake();

        ArchiveActivityLogsJob::dispatch(90);

        Queue::assertPushed(ArchiveActivityLogsJob::class, function ($job) {
            return $job->days === 90;
        });
    }

    public function test_archive_activity_logs_job_handles_empty_table(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $job = new ArchiveActivityLogsJob(90, [$unit->id]);
        $result = $job->handle();

        $this->assertEquals(0, $result);
    }

    public function test_archive_activity_logs_job_removes_old_logs(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $this->seedActivityLogs($user->id);

        $this->assertEquals(2, \DB::table('activity_logs')->count());

        $job = new ArchiveActivityLogsJob(90, [$unit->id]);
        $job->handle();

        $this->assertEquals(1, \DB::table('activity_logs')->count());
        $this->assertDatabaseMissing('activity_logs', ['description' => 'Old log']);
    }

    // ── CleanNotificationsJob ─────────────────────────────────────────

    public function test_clean_notifications_job_is_dispatched(): void
    {
        Queue::fake();

        CleanNotificationsJob::dispatch(7);

        Queue::assertPushed(CleanNotificationsJob::class, function ($job) {
            return $job->days === 7;
        });
    }

    public function test_clean_notifications_job_removes_old_notifications(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $this->seedNotifications($user->id);

        $this->assertEquals(2, \DB::table('notifications')->count());

        $job = new CleanNotificationsJob(7, [$unit->id]);
        $result = $job->handle();

        $this->assertDatabaseMissing('notifications', ['title' => 'Old notification']);
        $this->assertDatabaseHas('notifications', ['title' => 'Recent notification']);
    }

    public function test_clean_notifications_job_handles_empty_table(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $job = new CleanNotificationsJob(7, [$unit->id]);
        $result = $job->handle();

        $this->assertEquals(0, $result);
    }

    // ── Non-request-scoped execution (issue #836) ─────────────────────
    //
    // A queue worker and the scheduler have no authenticated user and no
    // session, so every one of these must resolve its unit scope WITHOUT
    // an actor. Before the fix they returned 0 and deleted/created nothing.

    public function test_archive_activity_logs_job_works_without_authenticated_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        Session::forget('current_unit_id');

        $this->assertNull(auth()->user(), 'Precondition: no authenticated user.');

        $this->seedActivityLogs($user->id);

        $result = (new ArchiveActivityLogsJob(90, []))->handle();

        $this->assertEquals(1, $result);
        $this->assertDatabaseMissing('activity_logs', ['description' => 'Old log']);
        $this->assertDatabaseHas('activity_logs', ['description' => 'Recent log']);
    }

    public function test_clean_notifications_job_works_without_authenticated_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        Session::forget('current_unit_id');

        $this->assertNull(auth()->user(), 'Precondition: no authenticated user.');

        $this->seedNotifications($user->id);

        $result = (new CleanNotificationsJob(7, []))->handle();

        $this->assertEquals(1, $result);
        $this->assertDatabaseMissing('notifications', ['title' => 'Old notification']);
        $this->assertDatabaseHas('notifications', ['title' => 'Recent notification']);
    }

    // ── Org-wide retention covers users without a usable unit (#1280) ──
    //
    // An org-wide run (empty $unitIds) is every live user. A person row with
    // u_id = null is legal in the schema (nullable FK, ON DELETE SET NULL),
    // and such an account used to be skipped by both jobs. A scoped run must
    // keep skipping it: the boundary is pinned by the two scoped tests.

    public function test_clean_notifications_job_org_wide_cleans_user_without_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->person->update(['u_id' => null]);

        $this->seedNotifications($user->id);

        $result = (new CleanNotificationsJob(7, []))->handle();

        $this->assertSame(1, $result);
        $this->assertDatabaseMissing('notifications', ['title' => 'Old notification']);
        $this->assertDatabaseHas('notifications', ['title' => 'Recent notification']);
    }

    public function test_archive_activity_logs_job_org_wide_cleans_user_without_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->person->update(['u_id' => null]);

        $this->seedActivityLogs($user->id); // Old = 100d، Recent = 10d

        $result = (new ArchiveActivityLogsJob(90, []))->handle();

        $this->assertSame(1, $result);
        $this->assertDatabaseMissing('activity_logs', ['description' => 'Old log']);
        $this->assertDatabaseHas('activity_logs', ['description' => 'Recent log']);
    }

    public function test_clean_notifications_job_scoped_leaves_user_without_unit_untouched(): void
    {
        ['user' => $inScope, 'unit' => $unit] = $this->createUserWithUnit();

        $outOfScope = User::factory()->create();
        $outOfScope->person->update(['u_id' => null]);

        $this->seedNotifications($inScope->id);
        $this->seedNotifications($outOfScope->id);

        $result = (new CleanNotificationsJob(7, [$unit->id]))->handle();

        $this->assertSame(1, $result);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $inScope->id, 'title' => 'Old notification',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $outOfScope->id, 'title' => 'Old notification',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $outOfScope->id, 'title' => 'Recent notification',
        ]);
    }

    public function test_archive_activity_logs_job_scoped_leaves_user_without_unit_untouched(): void
    {
        ['user' => $inScope, 'unit' => $unit] = $this->createUserWithUnit();

        $outOfScope = User::factory()->create();
        $outOfScope->person->update(['u_id' => null]);

        $this->seedActivityLogs($inScope->id);
        $this->seedActivityLogs($outOfScope->id);

        $result = (new ArchiveActivityLogsJob(90, [$unit->id]))->handle();

        $this->assertSame(1, $result);
        $this->assertDatabaseMissing('activity_logs', [
            'user_id' => $inScope->id, 'description' => 'Old log',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $outOfScope->id, 'description' => 'Old log',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $outOfScope->id, 'description' => 'Recent log',
        ]);
    }

    public function test_generate_daily_reports_job_creates_reports_without_authenticated_user(): void
    {
        Session::flush();
        Unit::factory()->count(2)->create();

        $this->assertNull(auth()->user(), 'Precondition: no authenticated user.');

        (new GenerateDailyReportsJob)->handle();

        $this->assertDatabaseCount('daily_reports', 2);
    }

    // ── GenerateDailyReportsJob ───────────────────────────────────────

    public function test_generate_daily_reports_job_is_dispatched(): void
    {
        Queue::fake();

        GenerateDailyReportsJob::dispatch();

        Queue::assertPushed(GenerateDailyReportsJob::class);
    }

    // ── Job properties ────────────────────────────────────────────────

    public function test_jobs_implement_should_queue(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ArchiveActivityLogsJob);
        $this->assertInstanceOf(ShouldQueue::class, new CleanNotificationsJob);
        $this->assertInstanceOf(ShouldQueue::class, new GenerateDailyReportsJob);
    }

    public function test_jobs_have_retry_configured(): void
    {
        $this->assertGreaterThan(0, (new ArchiveActivityLogsJob)->tries);
        $this->assertGreaterThan(0, (new CleanNotificationsJob)->tries);
        $this->assertGreaterThan(0, (new GenerateDailyReportsJob)->tries);
    }

    // ── Queue connection pinning (#1010) ──────────────────────────────

    /**
     * The three jobs whose `$timeout` (300s/600s) is far above the 90 seconds
     * the default `redis` connection reserves a job for, paired with the
     * connection scoped to them in `config/queue.php`.
     *
     * @return array<class-string, array{0: ArchiveActivityLogsJob|CleanNotificationsJob|GenerateDailyReportsJob, 1: string}>
     */
    private function longRunningJobs(): array
    {
        return [
            ArchiveActivityLogsJob::class => [new ArchiveActivityLogsJob(90), 'redis-maintenance'],
            CleanNotificationsJob::class => [new CleanNotificationsJob(7), 'redis-maintenance'],
            GenerateDailyReportsJob::class => [new GenerateDailyReportsJob, 'redis-reports'],
        ];
    }

    public function test_long_running_jobs_pin_the_connection_scoped_to_them(): void
    {
        foreach ($this->longRunningJobs() as $class => [$job, $expectedConnection]) {
            $this->assertSame(
                $expectedConnection,
                $this->declaredQueueConnection($job),
                "{$class} must declare #[Connection('{$expectedConnection}')]."
            );
        }
    }

    /**
     * `retry_after` is the reservation window, not a retry delay: when it closes
     * while the first attempt is still running, a second worker reserves the
     * same job and the work runs twice concurrently — the retention jobs delete
     * rows and the reports job writes them. Strictly-greater is the invariant;
     * equality is already too late, because the reservation starts before the
     * work does (#1010).
     */
    public function test_scoped_connections_reserve_long_jobs_past_their_own_timeout(): void
    {
        foreach ($this->longRunningJobs() as $class => [$job, $expectedConnection]) {
            $retryAfter = config("queue.connections.{$expectedConnection}.retry_after");

            $this->assertIsInt($retryAfter);
            $this->assertGreaterThan(
                $job->timeout,
                $retryAfter,
                "queue.connections.{$expectedConnection}.retry_after ({$retryAfter}s) must be ".
                "greater than {$class}::\$timeout ({$job->timeout}s)."
            );
        }
    }

    /**
     * The pin has to hold on the path the framework really uses: the
     * `#[Connection]` attribute is what `Dispatcher::dispatchToQueue()` reads to
     * pick the queue resolver, and that name is what selects the Redis list.
     */
    public function test_dispatching_long_running_jobs_resolves_their_scoped_connection(): void
    {
        $resolvedConnections = [];

        $dispatcher = new Dispatcher($this->app, function ($connection) use (&$resolvedConnections) {
            $resolvedConnections[] = $connection;

            return $this->app['queue']->connection('database');
        });

        foreach ($this->longRunningJobs() as [$job]) {
            $dispatcher->dispatchToQueue($job);
        }

        $this->assertSame(
            ['redis-maintenance', 'redis-maintenance', 'redis-reports'],
            $resolvedConnections
        );
    }

    /**
     * `Queueable::onConnection()` is the setter the framework calls with a
     * resolved name; a job that redefines it as a getter leaves the connection
     * property null and the job silently lands back on the default connection
     * (#1010).
     */
    public function test_pinning_does_not_break_the_queueable_connection_setter(): void
    {
        $job = new ArchiveActivityLogsJob(90);

        $this->assertSame($job, $job->onConnection('redis'));
        $this->assertSame('redis', $job->connection);
    }

    /**
     * Guards the other direction: the two short jobs are correct on the default
     * connection (30s < 90s), so a future edit pinning them has to be deliberate
     * rather than a copy of the pattern above.
     */
    public function test_short_jobs_are_not_pinned_to_a_long_running_connection(): void
    {
        $jobs = [
            new SyncZabbixJob,
            new SendNotificationJob(1, 'test', 'Test notification'),
        ];

        foreach ($jobs as $job) {
            $this->assertSame(
                [],
                (new \ReflectionClass($job))->getAttributes(Connection::class),
                $job::class.' declares a 30s timeout and must stay on the default connection.'
            );
        }
    }

    private function declaredQueueConnection(object $job): \UnitEnum|string|null
    {
        $attribute = (new \ReflectionClass($job))->getAttributes(Connection::class)[0] ?? null;

        return $attribute?->newInstance()->connection;
    }

    /**
     * One old row (past every retention window) plus one recent row that must
     * survive. Shared so the retention tests cannot drift apart.
     */
    private function seedActivityLogs(int $userId): void
    {
        \DB::table('activity_logs')->insert([
            [
                'user_id' => $userId,
                'type' => 'test',
                'description' => 'Old log',
                'created_at' => now()->subDays(100),
                'updated_at' => now()->subDays(100),
            ],
            [
                'user_id' => $userId,
                'type' => 'test',
                'description' => 'Recent log',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
        ]);
    }

    private function seedNotifications(int $userId): void
    {
        \DB::table('notifications')->insert([
            [
                'id' => \Str::uuid()->toString(),
                'user_id' => $userId,
                'type' => 'test',
                'title' => 'Old notification',
                'body' => 'Body',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
            [
                'id' => \Str::uuid()->toString(),
                'user_id' => $userId,
                'type' => 'test',
                'title' => 'Recent notification',
                'body' => 'Body',
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ],
        ]);
    }
}
