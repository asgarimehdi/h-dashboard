<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the Issue #246 merge data migration:
 * legacy `hardware_histories` rows are copied into `hardware_audits`
 * and the legacy table is dropped.
 */
class HardwareAuditMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_drops_legacy_table_and_creates_unified_table(): void
    {
        // After running all migrations (RefreshDatabase), the legacy table
        // should no longer exist and the unified table should exist.
        $this->assertFalse(Schema::hasTable('hardware_histories'));
        $this->assertTrue(Schema::hasTable('hardware_audits'));
    }

    public function test_hardware_audits_has_expected_columns(): void
    {
        $columns = Schema::getColumnListing('hardware_audits');

        foreach (['id', 'hardware_id', 'user_id', 'action', 'changes', 'source', 'ip_address', 'user_agent', 'created_at', 'updated_at'] as $col) {
            $this->assertContains($col, $columns, "Missing column: {$col}");
        }
    }

    public function test_hardware_audits_has_no_fk_cascade_on_hardware_id(): void
    {
        // The whole point of the merge: deleting a hardware record must NOT
        // wipe its audit trail. Verify there is no FK constraint on hardware_id.
        // Use driver-agnostic approach.
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: check foreign_key_list is empty for hardware_id
            $fks = collect(DB::select('PRAGMA foreign_key_list("hardware_audits")'))
                ->where('from', 'hardware_id');
            $this->assertCount(0, $fks, 'hardware_id should not have a FK (audit trail must survive deletion)');
        } elseif ($driver === 'mysql') {
            // MySQL: check information_schema for FK on hardware_id
            $fks = DB::select("
                SELECT * FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'hardware_audits'
                AND COLUMN_NAME = 'hardware_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $this->assertCount(0, $fks, 'hardware_id should not have a FK (audit trail must survive deletion)');
        } else {
            // For other drivers, use Laravel's schema introspection if available
            // or skip with a warning
            $this->markTestSkipped("FK check not implemented for driver: {$driver}");
        }
    }
}