<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1566 — two migrations built overlapping pg_trgm GIN indexes on the
 * same hardwares columns under different names, and `IF NOT EXISTS` could not
 * deduplicate them because PostgreSQL matches the relation name.
 *
 * The pin is the invariant, not the naming: exactly ONE trigram index per
 * searched column. A name-only assertion would have missed the original bug,
 * because the duplicate arrived under a third naming scheme.
 */
class HardwareTrgmIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Six `hardwares_*` indexes plus the `n_code` outlier that must survive. */
    private const KEPT_INDEXES = [
        'hardwares_pc_name_trgm_idx',
        'hardwares_comments_trgm_idx',
        'hardwares_type_trgm_idx',
        'hardwares_ip_valid_trgm_idx',
        'hardwares_ip_local_trgm_idx',
        'hardwares_mac_trgm_idx',
        'idx_hardware_n_code_trgm',
    ];

    private const DROPPED_INDEXES = [
        'idx_hardware_pc_name_trgm',
        'idx_hardware_comments_trgm',
        'idx_hardware_ip_valid_trgm',
        'idx_hardware_ip_local_trgm',
        'idx_hardware_mac_trgm',
    ];

    private const SEARCHABLE_COLUMNS = [
        'pc_name',
        'comments',
        'type',
        'ip_valid',
        'ip_local',
        'mac',
        'n_code',
    ];

    public function test_hardwares_has_exactly_one_trigram_index_per_searchable_column(): void
    {
        $this->skipUnlessPostgres();

        $trigramIndexes = $this->trigramIndexes();

        foreach (self::KEPT_INDEXES as $index) {
            $this->assertArrayHasKey($index, $trigramIndexes, "{$index} must exist after the cleanup");
        }

        foreach (self::DROPPED_INDEXES as $index) {
            $this->assertArrayNotHasKey($index, $trigramIndexes, "{$index} is a duplicate and must be dropped");
        }

        $this->assertCount(
            7,
            $trigramIndexes,
            'hardwares must carry exactly seven trigram indexes: six hardwares_* plus idx_hardware_n_code_trgm'
        );

        $indexesPerColumn = array_count_values($trigramIndexes);

        foreach (self::SEARCHABLE_COLUMNS as $column) {
            $this->assertSame(
                1,
                $indexesPerColumn[$column] ?? 0,
                "hardwares.{$column} must be covered by exactly one trigram index, not a duplicate pair"
            );
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($indexesPerColumn), self::SEARCHABLE_COLUMNS)),
            'unexpected trigram index on a column outside the searched set'
        );
    }

    /**
     * `down()` is otherwise untested: rolling back must restore the duplicate
     * set and leave the kept family untouched, and re-migrating must drop the
     * duplicates again.
     */
    public function test_rolling_back_restores_the_duplicate_indexes_and_keeps_the_kept_family(): void
    {
        $this->skipUnlessPostgres();

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_16_000001_drop_duplicate_hardware_trgm_indexes.php',
        ])->assertSuccessful();

        $trigramIndexes = $this->trigramIndexes();

        foreach (self::DROPPED_INDEXES as $index) {
            $this->assertArrayHasKey($index, $trigramIndexes, "down() must recreate {$index}");
        }

        foreach (self::KEPT_INDEXES as $index) {
            $this->assertArrayHasKey($index, $trigramIndexes, "down() must leave {$index} alone");
        }

        $this->assertCount(12, $trigramIndexes, 'the rollback deliberately restores the duplicate set');

        $this->artisan('migrate')->assertSuccessful();

        $this->assertCount(7, $this->trigramIndexes(), 're-migrating must drop the five duplicates again');
    }

    /**
     * @return array<string, string> index name => indexed column
     */
    private function trigramIndexes(): array
    {
        $rows = DB::select(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'hardwares'"
        );

        $indexes = [];

        foreach ($rows as $row) {
            if (! str_contains($row->indexdef, 'gin_trgm_ops')) {
                continue;
            }

            $matches = [];
            $matched = preg_match('/\(([a-z_]+) gin_trgm_ops\)/', $row->indexdef, $matches);

            $this->assertSame(1, $matched, "could not parse the indexed column out of {$row->indexname}");
            $indexes[$row->indexname] = $matches[1];
        }

        return $indexes;
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm is PostgreSQL-only.');
        }
    }
}
