<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Hardware::class);

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

    protected function forceDeleteFailure(): void
    {
        DB::connection()->beforeExecuting(function (string $query) {
            if (preg_match('/^delete\s+from\s+"?hardwares"?/i', $query)) {
                throw new RuntimeException('forced bulk delete failure');
            }
        });
    }

    /**
     * @param  array<int>  $ids
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $ids = [];
        for ($i = 1; $i <= 2; $i++) {
            $hardware = Hardware::create([
                'n_code' => $user->n_code,
                'pc_name' => "PC-Fail-Api-{$i}",
                'type' => 'pc',
            ]);
            $ids[] = $hardware->id;
        }

        $this->actingAs($user);
        $this->forceDeleteFailure();

        $this->postJson('/api/hardware/bulk-delete', ['ids' => $ids])
            ->assertStatus(500);

        foreach ($ids as $id) {
            $this->assertDatabaseHas('hardwares', ['id' => $id]);
        }

        $this->assertNoBulkDeleteAudits($ids);
    }

    public function test_web_bulk_delete_failure_writes_no_audit_rows(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $ids = [];
        for ($i = 1; $i <= 2; $i++) {
            $hardware = Hardware::create([
                'n_code' => $user->n_code,
                'pc_name' => "PC-Fail-Web-{$i}",
                'type' => 'pc',
            ]);
            $ids[] = $hardware->id;
        }

        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $this->forceDeleteFailure();

        try {
            Livewire::test('hardware.index')
                ->set('selected', $ids)
                ->call('bulkDelete');
        } catch (\Throwable $e) {
            // expected
        }

        foreach ($ids as $id) {
            $this->assertDatabaseHas('hardwares', ['id' => $id]);
        }

        $this->assertNoBulkDeleteAudits($ids);
    }
}
