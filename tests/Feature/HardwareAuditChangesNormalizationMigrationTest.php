<?php

namespace Tests\Feature;

use App\Exports\HardwareAuditsExport;
use App\Models\HardwareAudit;
use App\Support\HardwareAuditChange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #927: repair the `hardware_audits.changes` rows the old `bulkDelete()` wrote
 * as a column => value map instead of the documented `[{field, old, new}]` list.
 *
 * Those rows cannot be removed from the UI (there is no delete route for an
 * audit row) and they outlive the device by design — `hardware_id` carries no FK
 * precisely so the trail survives hardware deletion.
 */
covers(HardwareAudit::class);

class HardwareAuditChangesNormalizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_09_000001_normalize_bulk_delete_hardware_audit_changes.php';

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function audit(string $action, mixed $changes): HardwareAudit
    {
        $id = HardwareAudit::insertGetId([
            'hardware_id' => 1,
            'action' => $action,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'source' => 'bulk',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return HardwareAudit::query()->findOrFail($id);
    }

    public function test_a_map_shaped_bulk_delete_row_is_rewritten_as_a_diff_list(): void
    {
        $audit = $this->audit('bulk_delete', [
            'n_code' => '1234567890',
            'pc_name' => 'PC-1',
            'ram' => '8192',
        ]);

        $this->assertFalse(array_is_list($audit->changes), 'precondition: the row starts out as a map');

        $this->migration()->up();

        $repaired = $audit->fresh();

        $this->assertTrue(array_is_list($repaired->changes));
        foreach ($repaired->changes as $entry) {
            $this->assertIsArray($entry);
            $this->assertArrayHasKey('field', $entry);
            $this->assertArrayHasKey('old', $entry);
            $this->assertArrayHasKey('new', $entry);
        }

        $byField = collect($repaired->changes)->keyBy('field');
        $this->assertSame('PC-1', $byField['pc_name']['old']);
        $this->assertSame(HardwareAuditChange::NO_VALUE, $byField['pc_name']['new']);
    }

    public function test_the_action_and_the_who_when_columns_are_preserved(): void
    {
        // The migration must not blank `changes` and lose the rest of the
        // trail: the schema exists so the history outlives the device.
        $audit = $this->audit('bulk_delete', ['pc_name' => 'PC-1']);

        $this->migration()->up();

        $repaired = $audit->fresh();

        $this->assertSame('bulk_delete', $repaired->action);
        $this->assertSame('bulk', $repaired->source);
        $this->assertSame(1, $repaired->hardware_id);
        $this->assertNotEmpty($repaired->changes);
    }

    public function test_already_well_shaped_rows_are_left_untouched(): void
    {
        $wellShaped = $this->audit('updated', [
            ['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7'],
        ]);
        $before = $wellShaped->changes;

        $this->migration()->up();

        $this->assertSame($before, $wellShaped->fresh()->changes);
    }

    public function test_a_deleted_row_with_no_payload_is_left_null(): void
    {
        $deleted = $this->audit('deleted', null);

        $this->migration()->up();

        $this->assertNull($deleted->fresh()->changes);
    }

    public function test_the_migration_is_idempotent(): void
    {
        $audit = $this->audit('bulk_delete', ['pc_name' => 'PC-1']);

        $this->migration()->up();
        $afterFirstRun = $audit->fresh()->changes;

        $this->migration()->up();

        $this->assertSame($afterFirstRun, $audit->fresh()->changes);
    }

    public function test_repaired_rows_reach_the_export(): void
    {
        $audit = $this->audit('bulk_delete', ['pc_name' => 'PC-1', 'ram' => '8192']);

        $this->migration()->up();

        $summary = (new HardwareAuditsExport(HardwareAudit::query()))
            ->map($audit->fresh())[3];

        $this->assertStringContainsString('pc_name: PC-1', $summary);
        $this->assertStringContainsString('ram: 8192', $summary);
    }
}
