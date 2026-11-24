<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1760 — `reports:generate-daily` was check-then-act (exists() then create())
 * with no lock and no constraint, so two runs racing wrote two rows for the
 * same unit+day and every aggregate over `daily_reports` counted that unit
 * twice. This index is the guarantee; the command's firstOrCreate() turns the
 * losing INSERT into a read of the winner's row.
 *
 * The dedupe is a one-shot repair and is NOT reversible: down() drops only the
 * index, the deleted duplicates stay deleted. Highest `id` wins — ids are
 * serial, `created_at` only has second resolution, so rows from one run can tie.
 * `unit_id IS NULL` rows (units deleted → daily_report_unit_fk SET NULL) are
 * skipped on purpose: Postgres treats NULLs as distinct, so the index never
 * constrains them either.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM daily_reports a
            USING daily_reports b
            WHERE a.unit_id IS NOT NULL
              AND a.unit_id = b.unit_id
              AND a.report_date = b.report_date
              AND a.id < b.id
        SQL);

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->unique(['unit_id', 'report_date'], 'daily_reports_unit_id_report_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropUnique('daily_reports_unit_id_report_date_unique');
        });
    }
};
