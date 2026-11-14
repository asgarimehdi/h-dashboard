<?php

use App\Traits\PersianNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Issue #1482 — Persian search folded the COLUMN at query time
     * (`PersianNormalizer::foldSeparatorsSql()`), which wraps it in
     * `regexp_replace(...)` + `translate(...)`. No trigram index can match that
     * expression, so every folded search site was a sequential scan, and a GIN
     * index that were built on the expression would still re-evaluate the eight
     * fold calls on each candidate row during its lossy recheck.
     *
     * A stored generated column moves the fold to write time and lets a plain
     * `gin_trgm_ops` index on the column serve the search:
     *
     *   - it works for the full-name forms, where an expression index would have
     *     to use `||` (a `CONCAT()` expression is STABLE, not IMMUTABLE, and
     *     PostgreSQL rejects it with 42P17);
     *   - the recheck is a plain `text LIKE`, not another fold;
     *   - it is correct on EVERY write path — PostgreSQL computes it for raw
     *     `DB::table()->insert()` too, unlike a `saving` hook, which is not an
     *     invariant (legacy rows, units and semats never had one);
     *   - it is one definition, not N call-site expressions.
     *
     * `foldSeparatorsSql()` stays the single source of truth: it generates these
     * column definitions, and `FoldedColumnsTest` compares the catalog back
     * against it so a change to `charMap()`/`digitMap()` without rebuilding the
     * columns fails loudly instead of silently returning stale rows.
     *
     * Hardware text columns are deliberately absent: `applySearch`/`applyText`
     * compare the BARE columns (`pc_name`, `comments`, ...), which are already
     * trigram-indexed and are normalized by the model's `saving` hook; a folded
     * copy there would only double the storage.
     *
     * `ADD COLUMN ... STORED` rewrites the table under an ACCESS EXCLUSIVE lock
     * (~2.4 s per column for 120k persons in the measured spike; units and
     * semats are instant). `lock_timeout` makes a blocked deploy fail fast
     * instead of queueing every writer behind it.
     */
    private const COLUMNS = [
        'persons' => [
            ['f_name_folded', 'f_name'],
            ['l_name_folded', 'l_name'],
            ['full_name_folded', "(f_name || ' ' || l_name)"],
            ['full_name_rev_folded', "(l_name || ' ' || f_name)"],
        ],
        'units' => [['name_folded', 'name']],
        'semats' => [['name_folded', 'name']],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // The trait's helpers are static; a throwaway consumer keeps the call
        // shape that PHP 8.1+ expects (calling a static method on the trait
        // itself is deprecated).
        $normalizer = new class
        {
            use PersianNormalizer;
        };

        DB::statement("SET LOCAL lock_timeout = '5s'");

        foreach (self::COLUMNS as $table => $columns) {
            $definitions = implode(', ', array_map(
                fn (array $column): string => "ADD COLUMN {$column[0]} text GENERATED ALWAYS AS ({$normalizer->foldSeparatorsSql($column[1])}) STORED",
                $columns
            ));

            DB::statement("ALTER TABLE {$table} {$definitions}");

            foreach ($columns as [$column]) {
                DB::statement("CREATE INDEX {$table}_{$column}_trgm ON {$table} USING GIN ({$column} gin_trgm_ops)");
            }

            DB::statement("ANALYZE {$table}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Indexes first, then the columns they are built on. The BARE-column
        // trigram indexes are left alone on purpose: PersonController,
        // HrStatsController and the hardware autocomplete still search those
        // columns directly.
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as [$column]) {
                DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_trgm");
            }
        }

        foreach (self::COLUMNS as $table => $columns) {
            $drops = implode(', ', array_map(
                fn (array $column): string => "DROP COLUMN IF EXISTS {$column[0]}",
                $columns
            ));

            DB::statement("ALTER TABLE {$table} {$drops}");
        }
    }
};
