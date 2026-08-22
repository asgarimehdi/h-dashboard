<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Personnel search runs LIKE '%term%' against CONCAT(f_name,' ',l_name),
     * CONCAT(l_name,' ',f_name) and n_code — none of which can use a B-tree
     * index. A pg_trgm GIN index on the two concatenated name forms keeps the
     * search fast as the directory grows (#494 follow-up). The n_code prefix
     * is already covered by the PK.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Expression indexes matching the exact CONCAT forms used by the
        // personnel (and users) search queries. CONCAT is STABLE in Postgres
        // (not IMMUTABLE), so index expressions must use the || operator.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS persons_fullname_trgm_idx ON persons USING GIN (((f_name || ' ' || l_name)) gin_trgm_ops)"
        );
        DB::statement(
            "CREATE INDEX IF NOT EXISTS persons_fullname_rev_trgm_idx ON persons USING GIN (((l_name || ' ' || f_name)) gin_trgm_ops)"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS persons_fullname_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS persons_fullname_rev_trgm_idx');
    }
};
