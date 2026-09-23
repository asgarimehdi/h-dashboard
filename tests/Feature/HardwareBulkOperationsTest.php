<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareController;
use App\Models\Hardware;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareController::class);

class HardwareBulkOperationsTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createUserWithHardware(): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $nCode = $user->n_code;

        $hardware1 = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-1', 'type' => 'pc']);
        $hardware2 = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-2', 'type' => 'laptop']);
        $hardware3 = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-3', 'type' => 'server']);

        return ['user' => $user, 'unit' => $unit, 'hardware' => [$hardware1, $hardware2, $hardware3]];
    }

    // ==================== Bulk Mark ====================

    public function test_bulk_mark_sets_mark_on_multiple_hardware(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id, $data['hardware'][1]->id],
            'mark' => true,
        ])->assertOk();

        $this->assertDatabaseHas('hardwares', ['id' => $data['hardware'][0]->id, 'mark' => true]);
        $this->assertDatabaseHas('hardwares', ['id' => $data['hardware'][1]->id, 'mark' => true]);
        $this->assertDatabaseHas('hardwares', ['id' => $data['hardware'][2]->id, 'mark' => false]);
    }

    public function test_bulk_unmark_removes_mark(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        // First mark them
        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        // Then unmark
        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => false,
        ])->assertOk();

        $this->assertDatabaseHas('hardwares', ['id' => $data['hardware'][0]->id, 'mark' => false]);
    }

    // ==================== Bulk Delete ====================

    public function test_bulk_delete_removes_multiple_hardware(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$data['hardware'][0]->id, $data['hardware'][1]->id],
        ])->assertOk();

        $this->assertDatabaseMissing('hardwares', ['id' => $data['hardware'][0]->id]);
        $this->assertDatabaseMissing('hardwares', ['id' => $data['hardware'][1]->id]);
        $this->assertDatabaseHas('hardwares', ['id' => $data['hardware'][2]->id]);
    }

    public function test_bulk_delete_creates_audit_entries(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$data['hardware'][0]->id],
        ])->assertOk();

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $data['hardware'][0]->id,
            'action' => 'bulk_delete',
            'source' => 'bulk',
        ]);
    }

    // ==================== Auth ====================

    public function test_unauthenticated_user_cannot_bulk_mark(): void
    {
        $this->postJson('/api/hardware/bulk-mark', ['ids' => [1], 'mark' => true])
            ->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_bulk_delete(): void
    {
        $this->postJson('/api/hardware/bulk-delete', ['ids' => [1]])
            ->assertUnauthorized();
    }

    public function test_user_without_manage_hardware_cannot_bulk_mark(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->postJson('/api/hardware/bulk-mark', ['ids' => [1], 'mark' => true])
            ->assertForbidden();
    }
}
