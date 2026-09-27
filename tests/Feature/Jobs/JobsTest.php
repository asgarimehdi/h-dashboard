<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveActivityLogsJob;
use App\Jobs\CleanNotificationsJob;
use App\Jobs\GenerateDailyReportsJob;
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

        \DB::table('activity_logs')->insert([
            'user_id' => $user->id,
            'type' => 'test',
            'description' => 'Old log',
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        \DB::table('activity_logs')->insert([
            'user_id' => $user->id,
            'type' => 'test',
            'description' => 'Recent log',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

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

        \DB::table('notifications')->insert([
            'id' => \Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Old notification',
            'body' => 'Body',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        \DB::table('notifications')->insert([
            'id' => \Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Recent notification',
            'body' => 'Body',
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

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
}
