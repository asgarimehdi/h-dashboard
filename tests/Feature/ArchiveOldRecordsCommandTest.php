<?php

namespace Tests\Feature;

use App\Console\Commands\ArchiveOldRecords;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

covers(ArchiveOldRecords::class);

class ArchiveOldRecordsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_moves_old_records_to_archive_table(): void
    {
        $user = User::factory()->create();
        $old = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'old',
        ]);
        $old->forceFill(['created_at' => now()->subYears(2)])->save();
        $recent = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'recent',
        ]);
        $recent->forceFill(['created_at' => now()->subDays(1)])->save();

        $this->artisan('data:archive', ['--months' => 12])
            ->expectsOutputToContain('Archived 1 record(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('activity_log_archives', 1);
        $this->assertDatabaseHas('activity_log_archives', ['description' => 'old']);
    }

    public function test_archive_dry_run_moves_nothing(): void
    {
        $user = User::factory()->create();
        $old = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'old',
        ]);
        $old->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->artisan('data:archive', ['--months' => 12, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('activity_log_archives', 0);
    }

    public function test_archive_no_eligible_records_is_ok(): void
    {
        $this->artisan('data:archive', ['--months' => 12])
            ->expectsOutputToContain('Nothing to archive')
            ->assertExitCode(0);
    }
}
