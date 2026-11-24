<?php

namespace Tests\Feature;

use App\Models\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyReportUniqueIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_24_000001_add_unique_unit_date_to_daily_reports_table.php';

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    /** Re-create the pre-#1760 world: duplicates are legal again. */
    private function dropUniqueIndex(): void
    {
        Schema::table('daily_reports', function (Blueprint $table): void {
            $table->dropUnique('daily_reports_unit_id_report_date_unique');
        });
    }

    private function report(?int $unitId, string $date, string $summary): int
    {
        return DB::table('daily_reports')->insertGetId([
            'unit_id' => $unitId,
            'report_date' => $date,
            'summary' => $summary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicates_collapse_to_the_highest_id_before_the_constraint_lands(): void
    {
        $unit = Unit::create(['name' => 'Ward Dup']);
        $other = Unit::create(['name' => 'Ward Other']);

        $this->dropUniqueIndex();

        $this->report($unit->id, '2026-11-20', 'oldest');
        $this->report($unit->id, '2026-11-20', 'middle');
        $newest = $this->report($unit->id, '2026-11-20', 'newest');
        $untouched = $this->report($other->id, '2026-11-20', 'untouched');

        $this->migration()->up();

        $this->assertSame(2, DB::table('daily_reports')->count());
        $this->assertSame(
            ['newest'],
            DB::table('daily_reports')->where('unit_id', $unit->id)->pluck('summary')->all(),
        );
        $this->assertTrue(DB::table('daily_reports')->where('id', $newest)->exists());
        $this->assertTrue(DB::table('daily_reports')->where('id', $untouched)->exists());
    }

    public function test_null_unit_rows_are_not_deduped_and_do_not_block_the_index(): void
    {
        $this->dropUniqueIndex();

        $a = $this->report(null, '2026-11-20', 'deleted unit a');
        $b = $this->report(null, '2026-11-20', 'deleted unit b');

        $this->migration()->up();

        $this->assertTrue(DB::table('daily_reports')->where('id', $a)->exists());
        $this->assertTrue(DB::table('daily_reports')->where('id', $b)->exists());
    }

    public function test_a_duplicate_that_survives_the_dedupe_is_rejected_afterwards(): void
    {
        $unit = Unit::create(['name' => 'Ward Guarded']);
        $this->dropUniqueIndex();
        $this->report($unit->id, '2026-11-20', 'kept');

        $this->migration()->up();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($unit): void {
            $this->report($unit->id, '2026-11-20', 'duplicate');
        });
    }
}
