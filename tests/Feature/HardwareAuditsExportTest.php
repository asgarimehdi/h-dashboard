<?php

namespace Tests\Feature;

use App\Exports\HardwareAuditsExport;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #927: `hardware_audits.changes` is a field-level diff list, `[{field, old, new}]`
 * — the shape the migration documents and every other action writes.
 *
 * `HardwareController::bulkDelete()` was the one caller that handed
 * `$hw->getAttributes()` (a column => value map) to `batchInsertAudits()`, so it
 * stored a JSON **object**. `map()` interpolated `$c['field']` per element, and
 * on a map every element is a string, so the row threw a `TypeError` out of the
 * mapper — which kills the whole export stream, not just that row. This file is
 * the characterization test whose absence let the bug through.
 */
covers(HardwareAuditsExport::class);

class HardwareAuditsExportTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * Index of the 'تغییرات' column produced by headings().
     */
    private const CHANGES_COLUMN = 3;

    private function export(): HardwareAuditsExport
    {
        return new HardwareAuditsExport(HardwareAudit::query()->orderBy('id'));
    }

    /**
     * Write one audit row and read it back from the table, so the export sees
     * exactly what a later request would decode out of `changes`.
     */
    private function storedAudit(string $action, mixed $changes, string $source = 'bulk'): HardwareAudit
    {
        $id = HardwareAudit::insertGetId([
            'hardware_id' => 1,
            'action' => $action,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return HardwareAudit::query()->findOrFail($id);
    }

    private function summaryOf(mixed $changes, string $action = 'updated'): string
    {
        $mapped = $this->export()->map($this->storedAudit($action, $changes));

        return (string) $mapped[self::CHANGES_COLUMN];
    }

    // ==================== One case per documented action ====================

    public function test_created_row_renders_its_diff(): void
    {
        $summary = $this->summaryOf([
            ['field' => 'pc_name', 'old' => '—', 'new' => 'PC-1'],
        ], 'created');

        $this->assertSame('pc_name: — → PC-1', $summary);
    }

    public function test_updated_row_renders_its_diff(): void
    {
        $summary = $this->summaryOf([
            ['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7'],
            ['field' => 'ram', 'old' => '4096', 'new' => '8192'],
        ], 'updated');

        $this->assertSame('cpu: Intel i5 → Intel i7 | ram: 4096 → 8192', $summary);
    }

    public function test_deleted_row_has_no_diff_to_render(): void
    {
        $this->assertSame('', $this->summaryOf(null, 'deleted'));
    }

    public function test_bulk_mark_row_renders_its_diff(): void
    {
        $summary = $this->summaryOf([
            ['field' => 'mark', 'old' => false, 'new' => true],
        ], 'bulk_mark');

        $this->assertStringContainsString('mark:', $summary);
        $this->assertStringContainsString('→ 1', $summary);
    }

    public function test_rollback_row_renders_its_diff(): void
    {
        $summary = $this->summaryOf([
            ['field' => 'cpu', 'old' => 'Intel i7', 'new' => 'Intel i5'],
        ], 'rollback');

        $this->assertSame('cpu: Intel i7 → Intel i5', $summary);
    }

    public function test_bulk_delete_row_written_by_the_api_uses_the_diff_list(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $hardware = Hardware::create([
            'n_code' => $user->n_code,
            'pc_name' => 'PC-BULK',
            'type' => 'pc',
            'ram' => '8192',
            'mark' => false,
        ]);

        $this->actingAs($user)
            ->postJson('/api/hardware/bulk-delete', ['ids' => [$hardware->id]])
            ->assertOk();

        $audit = HardwareAudit::query()
            ->where('hardware_id', $hardware->id)
            ->where('action', 'bulk_delete')
            ->firstOrFail();

        // The migration documents `[{field, old, new}]`; bulk_delete used to
        // store a column => value map, which no reader could render.
        $this->assertIsArray($audit->changes);
        $this->assertTrue(array_is_list($audit->changes), 'bulk_delete must store a diff list, not an attribute map');

        foreach ($audit->changes as $change) {
            $this->assertIsArray($change);
            $this->assertArrayHasKey('field', $change);
            $this->assertArrayHasKey('old', $change);
            $this->assertArrayHasKey('new', $change);
        }

        $fields = array_column($audit->changes, 'field');
        $this->assertContains('pc_name', $fields);

        // The row is about to disappear, so the value it carries is `old`.
        $pcName = $audit->changes[array_search('pc_name', $fields, true)];
        $this->assertSame('PC-BULK', $pcName['old']);
        $this->assertSame('deleted', $pcName['new']);

        $summary = $this->export()->map($audit)[self::CHANGES_COLUMN];
        $this->assertStringContainsString('pc_name: PC-BULK → deleted', $summary);
    }

    // ==================== Legacy map-shaped rows ====================

    public function test_a_map_shaped_row_degrades_to_a_one_line_summary(): void
    {
        // Byte-for-byte what the old bulkDelete() wrote: getAttributes() is a
        // column => value map, so this decodes to an assoc array, not a list.
        $summary = $this->summaryOf([
            'n_code' => '1234567890',
            'pc_name' => 'PC-1',
            'ram' => '8192',
        ], 'bulk_delete');

        // `changes` is jsonb, which does not preserve key order — the export
        // must not depend on it, so compare the set of rendered fields.
        $this->assertEqualsCanonicalizing(
            ['n_code: 1234567890', 'pc_name: PC-1', 'ram: 8192'],
            explode(' | ', $summary)
        );
    }

    public function test_a_map_shaped_row_does_not_break_the_rows_around_it(): void
    {
        $export = $this->export();

        $good = $export->map($this->storedAudit('updated', [
            ['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7'],
        ]));
        $poisoned = $export->map($this->storedAudit('bulk_delete', ['pc_name' => 'PC-1']));
        $other = $export->map($this->storedAudit('created', [
            ['field' => 'pc_name', 'old' => '—', 'new' => 'PC-2'],
        ]));

        $this->assertSame('cpu: Intel i5 → Intel i7', $good[self::CHANGES_COLUMN]);
        $this->assertSame('pc_name: PC-1', $poisoned[self::CHANGES_COLUMN]);
        $this->assertSame('pc_name: — → PC-2', $other[self::CHANGES_COLUMN]);
    }

    public function test_a_list_of_scalars_degrades_instead_of_throwing(): void
    {
        $this->assertSame('pc_name | ram', $this->summaryOf(['pc_name', 'ram'], 'bulk_delete'));
    }

    public function test_an_empty_payload_renders_nothing(): void
    {
        $this->assertSame('', $this->summaryOf([], 'bulk_delete'));
    }

    public function test_export_actually_writes_a_file_with_a_map_shaped_row(): void
    {
        $this->storedAudit('updated', [
            ['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7'],
        ]);
        $this->storedAudit('bulk_delete', ['pc_name' => 'PC-1', 'ram' => '8192']);

        // This is the call that used to throw an uncaught TypeError from inside
        // the row mapper, losing every other device's history in the download.
        Excel::store($this->export(), 'hardware-audits-export-bad-row.xlsx', 'local');

        $path = Storage::disk('local')->path('hardware-audits-export-bad-row.xlsx');
        $this->assertFileExists($path);

        @unlink($path);
    }

    // ==================== Formula escaping (#886) ====================

    public function test_a_formula_payload_renders_as_a_cell_that_is_not_a_formula(): void
    {
        // PhpSpreadsheet binds a cell as TYPE_FORMULA only when the string
        // starts with '=', so what matters is that the rendered summary never
        // begins with one — the leading `field:` already guarantees that.
        $audit = $this->storedAudit('updated', [
            ['field' => 'comments', 'old' => 'ok', 'new' => '=1+1'],
        ]);

        $cell = (string) $this->export()->map($audit)[self::CHANGES_COLUMN];

        $this->assertStringStartsNotWith('=', $cell);
        $this->assertStringContainsString('=1+1', $cell);
    }

    public function test_a_summary_that_would_start_a_cell_with_an_equals_sign_is_escaped(): void
    {
        // The only shape that puts '=' in first position: a bare scalar list.
        $cell = $this->summaryOf(['=1+1'], 'bulk_delete');

        $this->assertSame("'=1+1", $cell);
    }

    public function test_mapping_does_not_mutate_the_stored_payload(): void
    {
        $audit = $this->storedAudit('updated', [
            ['field' => 'comments', 'old' => 'ok', 'new' => '=1+1'],
        ]);

        $this->export()->map($audit);

        $this->assertSame('=1+1', $audit->fresh()->changes[0]['new']);
        $this->assertSame(
            '=1+1',
            json_decode((string) DB::table('hardware_audits')->where('id', $audit->id)->value('changes'), true)[0]['new']
        );
    }
}
