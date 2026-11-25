<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #1803 — `user_units.is_primary` means "the user's one primary unit", but
 * nothing enforced exactly-one. Every person transfer marked the new unit
 * primary without clearing the old one, so a user could accumulate two
 * primaries forever and `User::primaryUnit()` picked whichever row Postgres
 * returned first.
 *
 * The partial unique index is the invariant; `User::setPrimaryUnit()` is the
 * single write path that keeps it. The data repair runs first (otherwise the
 * index build fails on existing duplicates). The repair is a one-shot and is
 * NOT reversible: down() drops only the index, the demoted rows stay demoted.
 *
 * Tie-break: keep the primary whose unit matches the person's current
 * `u_id` (that is the unit a transfer just wrote), otherwise the highest
 * pivot `id` (serial and monotonic — `created_at` only has second
 * resolution). Postgres-only, like the other raw-DDL indexes in this repo
 * (`2026_08_26_000001_add_trgm_indexes_*`); the suite runs on pgsql.
 */
return new class extends Migration
{
    private const INDEX = 'user_units_one_primary_per_user';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $demoted = DB::update(<<<'SQL'
            UPDATE user_units AS uu
            SET is_primary = false, updated_at = now()
            WHERE uu.is_primary
              AND uu.id NOT IN (
                SELECT DISTINCT ON (x.user_id) x.id
                FROM (
                  SELECT uu2.id, uu2.user_id, (uu2.unit_id = p.u_id) AS matches_person
                  FROM user_units uu2
                  JOIN users u ON u.id = uu2.user_id
                  LEFT JOIN persons p ON p.n_code = u.n_code
                  WHERE uu2.is_primary
                ) x
                ORDER BY x.user_id, x.matches_person DESC NULLS LAST, x.id DESC
              )
        SQL);

        if ($demoted > 0) {
            Log::info('user_units primary repair: demoted duplicate primaries', ['rows' => $demoted]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON user_units (user_id) WHERE is_primary'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
