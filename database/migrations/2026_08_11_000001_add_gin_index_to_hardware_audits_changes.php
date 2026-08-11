<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * hardware_audits.changes was created as `json` — GIN indexes only exist for
     * jsonb, so cast the column to jsonb (same data semantics, normalized keys)
     * and add a jsonb_path_ops GIN index to serve whereJsonContains (#454).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE hardware_audits ALTER COLUMN changes TYPE jsonb USING changes::jsonb');
        DB::statement(
            'CREATE INDEX hardware_audits_changes_gin_idx ON hardware_audits USING GIN (changes jsonb_path_ops)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS hardware_audits_changes_gin_idx');
        DB::statement('ALTER TABLE hardware_audits ALTER COLUMN changes TYPE json USING changes::text::json');
    }
};
