<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two migrations created overlapping pg_trgm GIN indexes on the same
     * hardwares columns: 2026_08_26_000001 used the `hardwares_<column>_trgm_idx`
     * family and 2026_09_06_000003 used `idx_hardware_<column>_trgm`. Because
     * `CREATE INDEX IF NOT EXISTS` resolves on the relation NAME, not on the
     * definition, both families were built and five pairs are byte-identical
     * duplicates (#1566).
     *
     * This migration drops the five `idx_hardware_*` copies. `type` exists only
     * in the `hardwares_*` family, and `idx_hardware_n_code_trgm` stays because
     * `n_code` exists only in the second family — so both families keep their
     * unique columns. `hardwares_type_trgm_idx` is the only
     * leading-wildcard-capable index on `type`: the B-tree
     * `hardwares_type_os_shutdown_index` also starts with `type` but cannot
     * serve `LIKE '%term%'`.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Pre-flight: refuse to drop unless every kept index is present. A
        // drifted deployment (the 08-26 migration failed there, or the family
        // was dropped by hand) would otherwise lose the LAST trigram indexes on
        // those five columns and search would silently regress. A throw here
        // rolls the migration transaction back, so no partial state remains.
        // Deliberately no self-healing `CREATE INDEX IF NOT EXISTS`: on a large
        // hardwares table it would build five GIN indexes synchronously and
        // block writes, which is worse than failing loudly.
        $keptIndexes = [
            'hardwares_pc_name_trgm_idx',
            'hardwares_comments_trgm_idx',
            'hardwares_type_trgm_idx',
            'hardwares_ip_valid_trgm_idx',
            'hardwares_ip_local_trgm_idx',
            'hardwares_mac_trgm_idx',
        ];

        $presentIndexes = array_column(
            DB::select(
                'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
                ['hardwares']
            ),
            'indexname'
        );

        $missingIndexes = array_values(array_diff($keptIndexes, $presentIndexes));

        if ($missingIndexes !== []) {
            throw new RuntimeException(
                'Refusing to drop the duplicate hardwares trigram indexes: the kept family is incomplete (missing: '
                .implode(', ', $missingIndexes)
                .'). Recreate the hardwares_* indexes (or re-run the 2026_08_26 migration) before applying this cleanup.'
            );
        }

        // Plain DROP INDEX inside the migration transaction: the app has no
        // production deployment (dev database only), so holding ACCESS
        // EXCLUSIVE on hardwares is theoretical. CONCURRENTLY would require
        // public $withinTransaction = false and lose transactionality.
        DB::statement('DROP INDEX IF EXISTS idx_hardware_pc_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_hardware_comments_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_hardware_ip_valid_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_hardware_ip_local_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_hardware_mac_trgm');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The rollback deliberately restores the duplicate set: it returns the
        // catalog to the state 2026_09_06_000003 left behind, duplicates included.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_hardware_pc_name_trgm ON hardwares USING GIN (pc_name gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_hardware_comments_trgm ON hardwares USING GIN (comments gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_hardware_ip_valid_trgm ON hardwares USING GIN (ip_valid gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_hardware_ip_local_trgm ON hardwares USING GIN (ip_local gin_trgm_ops)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_hardware_mac_trgm ON hardwares USING GIN (mac gin_trgm_ops)'
        );
    }
};
