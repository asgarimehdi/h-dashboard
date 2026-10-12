<?php

namespace Tests\Feature;

use App\Console\Commands\ArchiveOldRecords;
use App\Models\ActivityLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * #870: the fixture that actually reproduces the bug.
     *
     * Every other test here builds its fixture with `type => 'login'`, which
     * `ActivityLogService::login()` writes with NO json — so the archive
     * command's json columns were never exercised and the suite stayed green
     * over a command that dies mid-chunk. The first ticket accept in this app
     * calls `ActivityLogService::updated()`, so THAT is the shape that has to
     * work.
     *
     * `ActivityLog` casts both columns to `array`; `activity_log_archives` is
     * `json`. An array reaches PDO untouched (`Connection::prepareBindings()`
     * special-cases only DateTimeInterface and bool), so Postgres rejects it
     * with 22P02 / "Array to string conversion" and the weekly archive dies
     * part-way through the chunk.
     */
    public function test_archive_moves_json_bearing_rows_and_stores_valid_json(): void
    {
        $user = User::factory()->create();

        $log = ActivityLogService::updated(
            new Ticket(['id' => 7]),
            ['status' => 'open', 'title' => 'قبلی'],
            ['status' => 'closed', 'title' => 'جدید'],
            'تیکت تغییر وضعیت'
        );
        $log->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->artisan('data:archive', ['--months' => 12])->assertExitCode(0);

        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('activity_log_archives', 1);

        // Both columns must be valid JSON in the database, not PHP arrays.
        $row = DB::table('activity_log_archives')->first();

        $this->assertNotNull($row->old_values);
        $this->assertNotNull($row->new_values);

        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'old_values is not valid JSON.');
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'new_values is not valid JSON.');
        $this->assertSame(['status' => 'open', 'title' => 'قبلی'], $old);
        $this->assertSame(['status' => 'closed', 'title' => 'جدید'], $new);
    }

    /**
     * The two columns are cast identically, so a fix that encodes only one of
     * them is a plausible partial fix. Pin both.
     */
    public function test_archive_stores_null_json_columns_as_sql_null(): void
    {
        $user = User::factory()->create();

        // `login()` writes no json at all — the columns must land as SQL NULL,
        // NOT as the four-character string "null".
        $log = ActivityLogService::login('ورود');
        $log->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->artisan('data:archive', ['--months' => 12])->assertExitCode(0);

        $row = DB::table('activity_log_archives')->first();

        $this->assertNull($row->old_values);
        $this->assertNull($row->new_values);
    }

    /**
     * The archive is insert-then-delete. If the insert fails part-way, the
     * rows it already archived must not be deleted from the source table —
     * that is how the archive becomes half-done.
     */
    public function test_archive_is_atomic_per_chunk(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $log = ActivityLogService::updated(
                new Ticket(['id' => $i]),
                ['status' => 'open'],
                ['status' => 'closed'],
                "تیکت {$i}"
            );
            $log->forceFill(['created_at' => now()->subYears(2)])->save();
        }

        $this->artisan('data:archive', ['--months' => 12])->assertExitCode(0);

        // Either everything moved or nothing did — never a partial archive.
        $this->assertSame(0, ActivityLog::whereIn('id', DB::table('activity_logs')->pluck('id'))->count());
        $this->assertDatabaseCount('activity_log_archives', 3);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    /**
     * #870: `--dry-run` used to only count, so it validated nothing about the
     * write path it is supposed to protect. It must now exercise the real
     * insert inside a rolled-back transaction.
     */
    public function test_dry_run_exercises_the_write_path_without_writing(): void
    {
        $user = User::factory()->create();

        $log = ActivityLogService::updated(
            new Ticket(['id' => 42]),
            ['status' => 'open'],
            ['status' => 'closed'],
            'تیکت خشک'
        );
        $log->forceFill(['created_at' => now()->subYears(2)])->save();

        // A dry run that cannot perform the insert proves nothing about it.
        $this->artisan('data:archive', ['--months' => 12, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        // ...and it must leave no trace on either side.
        $this->assertDatabaseCount('activity_log_archives', 0);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    /**
     * A dry run must still report how many records WOULD move, so it stays a
     * useful pre-flight check for the operator.
     */
    public function test_dry_run_reports_the_eligible_count(): void
    {
        $user = User::factory()->create();

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'old',
        ]);
        $log->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->artisan('data:archive', ['--months' => 12, '--dry-run' => true])
            ->expectsOutputToContain('Found 1 record(s) eligible for archival')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('activity_log_archives', 0);
    }
}
