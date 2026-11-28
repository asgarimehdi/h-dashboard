<?php

use App\Traits\PersianNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Issue #1971 — the patient registry and the disease-case list folded the
     * patient name COLUMNS at query time
     * (`PersianNormalizer::foldSeparatorsSql()`), which wraps them in
     * `regexp_replace(...)` + `translate(...)`. No trigram index can serve that
     * expression, so both searches were sequential scans re-evaluating the fold
     * per row per term. `patients` was created after #1482 (which gave persons,
     * units and semats stored folded columns) and never got the same treatment.
     *
     * Same pattern as `2026_11_14_000001_add_folded_columns_for_persian_search`:
     * a stored generated column moves the fold to write time and a plain
     * `gin_trgm_ops` index on the column serves the search.
     *
     * The full-name columns must use `||`, not `CONCAT()`: `CONCAT` is STABLE,
     * not IMMUTABLE, and PostgreSQL rejects it in a generated column with
     * 42P17. `first_name` and `last_name` are both NOT NULL
     * (`2026_10_25_000001_create_patients_table`), so `||` and `CONCAT()` agree
     * on every row. If either ever becomes nullable, the generated expression
     * must become `coalesce(first_name, '') || ' ' || coalesce(last_name, '')`
     * to keep `CONCAT`'s null-skipping behaviour (and stay IMMUTABLE).
     *
     * `foldSeparatorsSql()` stays the single source of truth: it generates these
     * column definitions, and `FoldedColumnsTest` compares the catalog back
     * against it so a change to `charMap()`/`digitMap()` without rebuilding the
     * columns fails loudly instead of silently returning stale rows.
     *
     * `ADD COLUMN ... STORED` rewrites the table under an ACCESS EXCLUSIVE lock
     * for the whole rewrite. `lock_timeout` is only the ceiling on WAITING for
     * that lock (so a blocked deploy fails fast instead of queueing every
     * writer); it does not bound the rewrite. The registry is small today, but
     * the cost scales with the row count — deploy in a maintenance window if
     * `patients` has grown.
     */
    private const COLUMNS = [
        'patients' => [
            ['first_name_folded', 'first_name'],
            ['last_name_folded', 'last_name'],
            ['full_name_folded', "(first_name || ' ' || last_name)"],
            ['full_name_rev_folded', "(last_name || ' ' || first_name)"],
        ],
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

        // Indexes first, then the columns they are built on. The `kod_melli`
        // registry-key unique index is unrelated to the fold and is left alone.
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
