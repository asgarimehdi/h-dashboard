<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveActivityLogsJob;
use App\Jobs\CleanNotificationsJob;
use App\Jobs\GenerateDailyReportsJob;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
