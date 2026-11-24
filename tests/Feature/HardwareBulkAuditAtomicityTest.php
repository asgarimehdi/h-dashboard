<?php

namespace Tests\Feature;

use App\Imports\HardwareImport;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1744: a bulk hardware write and its audit rows must be one atomic unit.
 *
 * Both orderings are pinned across the four HTTP/Livewire sites: the existing
 * `HardwareBulkDeleteFailureTest` covers "the write fails, so no audit row is
 * written" (#1362); this file covers the mirror image — "the audit insert
 * fails, so the write is rolled back". Without the transaction the mutation
 * stands while its trail is missing, and a `bulk_delete` row is the only thing
 * that keeps a hard-deleted device visible in the trash.
 *
 * The import path is deliberately NOT wrapped here: `config/excel.php` sets
 * `transactions.handler = 'db'` and maatwebsite/excel wraps the whole sheet in
 * one DB transaction, so it is already atomic. The last test pins that
 * behaviour as a regression guard.
 */
covers(Hardware::class);

class HardwareBulkAuditAtomicityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Force the batched audit INSERT to fail at the DB boundary.
     *
     * Same idiom as `HardwareBulkDeleteFailureTest` — the SQL reaches the
     * connection and throws, so the rollback under test is a real database
     * rollback rather than a PHP exception thrown before the query runs.
     *
     * Must be installed AFTER the fixtures: `Hardware::create` writes a
     * `created` audit row of its own.
     */
    protected function failAuditInserts(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            if (preg_match('/^insert\s+into\s+"?hardware_audits"?/i', $query)) {
                throw new RuntimeException('forced audit failure');
            }
        });
    }

    /**
     * @return list<int>
     */
    protected function createUnmarkedHardware(string $nCode, int $count, string $prefix): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $hardware = Hardware::create([
                'n_code' => $nCode,
                'pc_name' => "{$prefix}-{$i}",
                'type' => 'pc',
                'mark' => false,
            ]);
            $ids[] = $hardware->id;
        }

        return $ids;
    }

    public function test_api_bulk_mark_rolls_back_when_the_audit_insert_fails(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $ids = $this->createUnmarkedHardware($user->n_code, 2, 'PC-Atomic-ApiMark');

        $this->actingAs($user);
        $this->failAuditInserts();

        $this->postJson('/api/hardware/bulk-mark', ['ids' => $ids, 'mark' => true])
            ->assertStatus(500);

        $this->assertSame(
            2,
            Hardware::whereIn('id', $ids)->where('mark', false)->count(),
            'a failed audit insert must roll the mass update back'
        );
        $this->assertSame(
            0,
            HardwareAudit::whereIn('hardware_id', $ids)->where('action', 'bulk_mark')->count(),
            'a failed audit insert must leave no bulk_mark row behind'
        );
    }

    public function test_api_bulk_delete_rolls_back_when_the_audit_insert_fails(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $ids = $this->createUnmarkedHardware($user->n_code, 2, 'PC-Atomic-ApiDelete');

        $this->actingAs($user);
        $this->failAuditInserts();

        $this->postJson('/api/hardware/bulk-delete', ['ids' => $ids])
            ->assertStatus(500);

        foreach ($ids as $id) {
            $this->assertDatabaseHas('hardwares', ['id' => $id]);
        }

        $this->assertSame(
            0,
            HardwareAudit::whereIn('hardware_id', $ids)->where('action', 'bulk_delete')->count(),
            'a failed audit insert must leave no bulk_delete row behind'
        );
    }

    public function test_web_bulk_mark_rolls_back_when_the_audit_insert_fails(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $ids = $this->createUnmarkedHardware($user->n_code, 2, 'PC-Atomic-WebMark');

        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $this->failAuditInserts();

        try {
            Livewire::test('hardware.index')
                ->set('selected', $ids)
                ->call('bulkMark', true);
        } catch (\Throwable $e) {
            // expected — the forced audit failure must surface, not be swallowed
        }

        $this->assertSame(
            2,
            Hardware::whereIn('id', $ids)->where('mark', false)->count(),
            'a failed audit insert must roll the web mass update back'
        );
        $this->assertSame(
            0,
            HardwareAudit::whereIn('hardware_id', $ids)->where('action', 'bulk_mark')->count()
        );
    }

    public function test_web_bulk_delete_rolls_back_when_the_audit_insert_fails(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $ids = $this->createUnmarkedHardware($user->n_code, 2, 'PC-Atomic-WebDelete');

        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $this->failAuditInserts();

        try {
            Livewire::test('hardware.index')
                ->set('selected', $ids)
                ->call('bulkDelete');
        } catch (\Throwable $e) {
            // expected — the forced audit failure must surface, not be swallowed
        }

        foreach ($ids as $id) {
            $this->assertDatabaseHas('hardwares', ['id' => $id]);
        }

        $this->assertSame(
            0,
            HardwareAudit::whereIn('hardware_id', $ids)->where('action', 'bulk_delete')->count()
        );
    }

    /**
     * Regression guard, not a red-first test: the Excel package already wraps
     * the whole sheet in one DB transaction (`transactions.handler = 'db'`), so
     * a failed audit insert rolls the imported row back before and after this
     * fix. If this ever goes red, the import lost its atomicity.
     */
    public function test_import_create_rolls_back_when_the_audit_insert_fails(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents(
            $file,
            "n_code\tpc_name\ttype\n{$user->n_code}\tPC-ATOMIC-IMPORT\tpc"
        );

        $import = new HardwareImport;
        $import->setAccessibleUnitIds([$unit->id]);
        $import->setSelectedActions(['row_2' => 'create']);

        $this->failAuditInserts();

        try {
            Excel::import($import, $file);
        } catch (\Throwable $e) {
            // expected — the forced audit failure must surface, not be swallowed
        }

        $this->assertSame(
            0,
            Hardware::where('pc_name', 'PC-ATOMIC-IMPORT')->count(),
            'the Excel sheet transaction must roll the inserted row back'
        );
        $this->assertSame(
            0,
            HardwareAudit::where('action', 'created')->where('source', 'import')->count(),
            'the import must not leave a created audit row behind'
        );

        @unlink($file);
    }
}
