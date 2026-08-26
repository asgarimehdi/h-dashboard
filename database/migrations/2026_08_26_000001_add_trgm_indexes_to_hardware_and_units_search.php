<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hardware and Unit search run LIKE '%term%' (leading-wildcard) against
     * pc_name, comments, type, ip_valid, ip_local, mac and units.name — none of
     * which can use a B-tree index, forcing a sequential scan that grows with the
     * table. A pg_trgm GIN index on each column keeps those searches fast as the
     * inventory grows (#perf follow-up to the persons trgm indexes).
     *
     * The persons fullname trgm indexes already cover person name search used
     * in the hardware/personnel JOINs, so they are not duplicated here.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Hardware columns searched with leading-wildcard LIKE in
        // HardwareController::index and GisController::hardware.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS hardwares_pc_name_trgm_idx ON hardwares USING GIN (pc_name gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS hardwares_comments_trgm_idx ON hardwares USING GIN (comments gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS hardwares_type_trgm_idx ON hardwares USING GIN (type gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS hardwares_ip_valid_trgm_idx ON hardwares USING GIN (ip_valid gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS hardwares_ip_local_trgm_idx ON hardwares USING GIN (ip_local gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS hardwares_mac_trgm_idx ON hardwares USING GIN (mac gin_trgm_ops)'
        );

        // units.name searched with leading-wildcard LIKE in OrgChart::search.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS units_name_trgm_idx ON units USING GIN (name gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS hardwares_pc_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS hardwares_comments_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS hardwares_type_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS hardwares_ip_valid_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS hardwares_ip_local_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS hardwares_mac_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS units_name_trgm_idx');
    }
};
