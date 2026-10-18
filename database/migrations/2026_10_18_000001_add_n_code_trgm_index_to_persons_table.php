<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The real personnel search (PersonController::index,
     * HrStatsController::personnel and the hardware.index person autocomplete)
     * is a FOUR-branch OR whose first arm is `n_code LIKE '%term%'`.
     *
     * PostgreSQL only builds a BitmapOr when every OR arm is index-covered;
     * one unindexed arm demotes the whole OR to a filter over a full
     * sequential scan — so `persons_fullname_trgm_idx`, `idx_persons_f_name_trgm`
     * and `idx_persons_l_name_trgm` were sitting idle for the real query even
     * after #1009 swapped `concat()` for `||` (#1126: verified with EXPLAIN on
     * the three sites).
     *
     * A pg_trgm GIN index on `n_code` covers the missing arm so all four arms
     * are index-eligible and the BitmapOr forms. `n_code` carries only a b-tree
     * (unique + `(u_id, n_code)` composites, all prefix-oriented) which cannot
     * serve a leading-wildcard LIKE.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_persons_n_code_trgm ON persons USING GIN (n_code gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_persons_n_code_trgm');
    }
};
