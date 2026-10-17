<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Unit;
use App\Models\User;
use App\Support\HardwareAuditChange;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1026: the web bulk delete must write the same `bulk_delete` audit row the
 * API path already writes.
 *
 * `bulkDelete()` used a mass delete, which bypasses the model `deleting` event,
 * and wrote no audit at all — so web-deleted devices were invisible in the
 * trash and could not be restored. The trash reader also only looked at
 * `action = 'deleted'`, which hid the API's `bulk_delete` rows too. These tests
 * pin the write, the read, the suppression window and the restore path.
 */
covers(Hardware::class);

class HardwareBulkDeleteAuditTest extends TestCase
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
     * @return array{user: User, unit: Unit, hardware: list<Hardware>}
     */
    protected function createUserWithHardware(int $count = 2, string $prefix = 'PC-BulkDelete'): array
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
     * @param  list<int>  $ids
     */
    protected function webBulkDelete(array $ids): void
    {
        Livewire::test('hardware.index')
            ->set('selected', $ids)
            ->call('bulkDelete');
    }

    // ==================== The write ====================

    public function test_web_bulk_delete_writes_one_bulk_delete_audit_per_device(): void
    {
        $data = $this->createUserWithHardware(2);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $ids = array_map(fn (Hardware $hw) => $hw->id, $data['hardware']);
        $this->webBulkDelete($ids);

        foreach ($ids as $id) {
            $this->assertDatabaseMissing('hardwares', ['id' => $id]);

            $this->assertDatabaseHas('hardware_audits', [
                'hardware_id' => $id,
                'action' => 'bulk_delete',
                'source' => 'bulk',
            ]);

            // Exactly one *deleting* row — the observer's `deleted` row must
            // have been suppressed, not written in addition.
            $this->assertSame(
                1,
                HardwareAudit::where('hardware_id', $id)
                    ->whereIn('action', HardwareAudit::DELETING_ACTIONS)
                    ->count()
            );
        }
    }

    public function test_web_bulk_delete_audit_holds_a_field_level_diff_and_actor(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;
        $this->webBulkDelete([$id]);

        $audit = HardwareAudit::where('hardware_id', $id)
            ->where('action', 'bulk_delete')
            ->firstOrFail();

        $this->assertTrue(array_is_list($audit->changes), 'changes must be a diff list');

        $byField = collect($audit->changes)->keyBy('field');
        $this->assertSame('PC-BulkDelete-1', $byField['pc_name']['old']);
        $this->assertSame(HardwareAuditChange::DELETED_VALUE, $byField['pc_name']['new']);

        // batchInsertAudits records the actor and request metadata; both must
        // survive being called from inside a Livewire request.
        $this->assertSame($data['user']->id, $audit->user_id);
        $this->assertNotNull($audit->ip_address);
    }

    public function test_suppress_audit_is_reset_after_a_web_bulk_delete(): void
    {
        $data = $this->createUserWithHardware(1);

        $this->assertFalse(Hardware::$suppressAudit);

        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);
        $this->webBulkDelete([$data['hardware'][0]->id]);

        $this->assertFalse(Hardware::$suppressAudit);
    }

    // ==================== The read (trash) ====================

    public function test_web_bulk_deleted_device_appears_in_trash_with_a_timestamp(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;
        $this->webBulkDelete([$id]);

        $dh = Livewire::test('hardware.index')->call('loadDeletedHardware')->get('deletedHardware');
        $row = collect($dh)->firstWhere('hardware_id', $id);

        $this->assertNotNull($row, 'a web bulk-deleted device must be listed in the trash');
        // The delete-timestamp query must also include the bulk action, or the
        // row renders without a «حذف شده در» date.
        $this->assertNotNull($row['deleted_at']);
    }

    public function test_api_bulk_deleted_device_appears_in_trash_and_restores(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;
        $this->postJson('/api/hardware/bulk-delete', ['ids' => [$id]])->assertOk();

        $dh = Livewire::test('hardware.index')->call('loadDeletedHardware')->get('deletedHardware');
        $row = collect($dh)->firstWhere('hardware_id', $id);

        $this->assertNotNull($row, 'an API bulk-deleted device must be listed in the trash');
        $this->assertNotNull($row['deleted_at']);

        // Restore anchors on the `created` audit, unchanged by this fix.
        $createdAudit = HardwareAudit::where('hardware_id', $id)
            ->where('action', 'created')
            ->firstOrFail();

        Livewire::test('hardware.index')->call('restoreRecord', $createdAudit->id);

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-BulkDelete-1']);
    }

    public function test_trash_hides_a_foreign_units_bulk_deleted_hardware(): void
    {
        $caller = $this->createUserWithUnit(['manage_hardware']);
        $foreign = $this->createUserWithUnit(['manage_hardware']);

        Session::put('current_unit_id', $caller['unit']->id);
        $this->actingAs($caller['user']);

        $own = Hardware::create([
            'n_code' => $caller['user']->n_code,
            'pc_name' => 'PC-OwnBulk',
            'type' => 'pc',
        ]);
        $foreignHw = Hardware::create([
            'n_code' => $foreign['user']->n_code,
            'pc_name' => 'PC-ForeignBulk',
            'type' => 'pc',
        ]);

        // The caller bulk-deletes its own device through the web path.
        $this->webBulkDelete([$own->id]);

        // …and the foreign user bulk-deletes theirs through the API path.
        Session::put('current_unit_id', $foreign['unit']->id);
        $this->actingAs($foreign['user'])
            ->postJson('/api/hardware/bulk-delete', ['ids' => [$foreignHw->id]])
            ->assertOk();

        $this->actingAs($caller['user']);
        Session::put('current_unit_id', $caller['unit']->id);

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('PC-OwnBulk')
            ->assertDontSee('PC-ForeignBulk');
    }

    // ==================== Both paths agree ====================

    public function test_web_and_api_bulk_delete_write_the_same_audit_shape(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $web = Hardware::create([
            'n_code' => $user->n_code, 'pc_name' => 'PC-Parity-Web', 'type' => 'pc', 'os' => 'Windows 10',
        ]);
        $api = Hardware::create([
            'n_code' => $user->n_code, 'pc_name' => 'PC-Parity-Api', 'type' => 'pc', 'os' => 'Windows 10',
        ]);

        $this->webBulkDelete([$web->id]);
        $this->postJson('/api/hardware/bulk-delete', ['ids' => [$api->id]])->assertOk();

        $webAudit = HardwareAudit::where('hardware_id', $web->id)->where('action', 'bulk_delete')->firstOrFail();
        $apiAudit = HardwareAudit::where('hardware_id', $api->id)->where('action', 'bulk_delete')->firstOrFail();

        $this->assertSame($apiAudit->action, $webAudit->action);
        $this->assertSame($apiAudit->source, $webAudit->source);
        $this->assertSame(
            array_keys(collect($apiAudit->changes)->keyBy('field')->all()),
            array_keys(collect($webAudit->changes)->keyBy('field')->all())
        );
    }
}
