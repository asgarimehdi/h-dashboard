<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1362: phantom `bulk_delete` audit rows when a bulk delete fails.
 *
 * Both the API path (HardwareController::bulkDelete) and the web path
 * (Livewire hardware.index::bulkDelete) wrote the audit BEFORE the delete.
 * If the delete failed (DB error, constraint, etc.), the audit row remained
 * — a phantom row for a device that still exists.
 *
 * These tests inject a DB-level failure on the DELETE query and assert:
 * 1. Hardware rows remain in the database (delete failed)
 * 2. Zero `bulk_delete` audit rows exist for those hardware IDs
 */
class HardwareBulkDeleteFailureTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createUserWithHardware(int $count = 2, string $prefix = 'PC-Fail'): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = [];
        for ($i = 1; $i <= $count; $i++) {
            $hardware[] = Hardware::create([
                'n_code' => $user->n_code,
                'pc_name' => "{$prefix}-{$i}",
                'type' => 'pc',
                'os' => 'Windows 10',
            ]);
        }

        return ['user' => $user, 'unit' => $unit, 'hardware' => $hardware];
    }

    /**
     * Inject a DB-level failure on DELETE FROM hardwares.
     * Uses Connection::beforeExecuting which fires for query builder deletes.
     */
    protected function forceDeleteFailure(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            if (preg_match('/^delete\s+from\s+"?hardwares"?/i', $query)) {
                throw new \RuntimeException('forced bulk delete failure');
            }
        });
    }

    /**
     * Assert zero bulk_delete audit rows for the given hardware IDs.
     *
     * @param  list<int>  $ids
     */
    protected function assertNoBulkDeleteAudits(array $ids): void
    {
        foreach ($ids as $id) {
            $this->assertSame(0, HardwareAudit::where('hardware_id', $id)
                ->where('action', 'bulk_delete')->count());
        }
    }

    public function test_api_bulk_delete_failure_writes_no_audit_rows(): void
    {
        $data = $this->createUserWithHardware(2, 'PC-Fail-Api');
        $this->actingAs($data['user']);

        $ids = array_map(fn (Hardware $hw) => $hw->id, $data['hardware']);

        $this->forceDeleteFailure();

        $this->postJson('/api/hardware/bulk-delete', ['ids' => $ids])
            ->assertStatus(500);

        // Hardware rows must still exist (delete failed)
        foreach ($ids as $id) {
            $this->assertDatabaseHas('hardwares', ['id' => $id]);
        }

        // Zero bulk_delete audit rows for these IDs
        $this->assertNoBulkDeleteAudits($ids);
    }

    public function test_web_bulk_delete_failure_writes_no_audit_rows(): void
    {
        $data = $this->createUserWithHardware(2, 'PC-Fail-Web');
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $ids = array_map(fn (Hardware $hw) => $hw->id, $data['hardware']);

        $this->forceDeleteFailure();

        Livewire::test('hardware.index')
            ->set('selected', $ids)
            ->call('bulkDelete');

        // Hardware rows must still exist (delete failed)
        foreach ($ids as $id) {
            $this->assertDatabaseHas('hardwares', ['id' => $id]);
        }

        // Zero bulk_delete audit rows for these IDs
        $this->assertNoBulkDeleteAudits($ids);
    }
}
