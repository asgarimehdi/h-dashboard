<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareController;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Support\HardwareAuditChange;
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

    /**
     * #927: `changes` is a field-level diff list, `[{field, old, new}]`.
     *
     * bulk_delete used to store `$hw->getAttributes()` — a column => value map —
     * so this assertion on action/source passed while every reader of the
     * payload failed. Asserting the shape here is what pins the writer.
     */
    public function test_bulk_delete_audit_entry_holds_a_field_level_diff(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$data['hardware'][0]->id],
        ])->assertOk();

        $audit = HardwareAudit::query()
            ->where('hardware_id', $data['hardware'][0]->id)
            ->where('action', 'bulk_delete')
            ->firstOrFail();

        $this->assertTrue(
            array_is_list($audit->changes),
            'bulk_delete must store a diff list, not a column => value map'
        );

        $byField = collect($audit->changes)->keyBy('field');

        $this->assertSame('PC-1', $byField['pc_name']['old']);
        $this->assertSame(HardwareAuditChange::DELETED_VALUE, $byField['pc_name']['new']);

        // The allowlist is shared with the observer's `created` writer, and
        // excludes id / timestamps.
        foreach (array_keys($byField->all()) as $field) {
            $this->assertContains($field, HardwareAuditChange::AUDITED_FIELDS);
        }
    }

    public function test_bulk_mark_audit_entry_holds_a_field_level_diff(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        $audit = HardwareAudit::query()
            ->where('hardware_id', $data['hardware'][0]->id)
            ->where('action', 'bulk_mark')
            ->firstOrFail();

        $this->assertTrue(array_is_list($audit->changes));
        $this->assertCount(1, $audit->changes);

        // `changes` is jsonb, which does not preserve key order — compare by key.
        $entry = $audit->changes[0];
        $this->assertSame('mark', $entry['field']);
        $this->assertFalse($entry['old']);
        $this->assertTrue($entry['new']);
    }

    // ==================== #1670: duplicate ids & phantom audits ====================

    public function test_bulk_mark_accepts_duplicate_ids(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $id = $data['hardware'][0]->id;

        // A retried/double-submitted client sends the same id twice. `whereIn`
        // collapses duplicates, so the accessibility count must compare against
        // the distinct ids instead of the raw payload (#1670).
        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$id, $id],
            'mark' => true,
        ])->assertOk();

        $this->assertDatabaseHas('hardwares', ['id' => $id, 'mark' => true]);

        $this->assertSame(1, HardwareAudit::query()
            ->where('hardware_id', $id)
            ->where('action', 'bulk_mark')
            ->count(), 'a duplicated id must not produce a second audit row');
    }

    public function test_bulk_delete_accepts_duplicate_ids(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $id = $data['hardware'][0]->id;

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$id, $id],
        ])->assertOk();

        $this->assertDatabaseMissing('hardwares', ['id' => $id]);

        $this->assertSame(1, HardwareAudit::query()
            ->where('hardware_id', $id)
            ->where('action', 'bulk_delete')
            ->count(), 'a duplicated id must not produce a second audit row');
    }

    public function test_bulk_mark_skips_the_audit_row_for_a_device_already_at_the_target_value(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $alreadyMarked = Hardware::create([
            'n_code' => $data['user']->n_code,
            'pc_name' => 'PC-ALREADY-MARKED',
            'type' => 'pc',
            'mark' => true,
        ]);

        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$alreadyMarked->id],
            'mark' => true,
        ])->assertOk();

        $this->assertSame(0, HardwareAudit::query()
            ->where('hardware_id', $alreadyMarked->id)
            ->where('action', 'bulk_mark')
            ->count(), 'a no-op bulk mark must not write an audit row');
    }

    public function test_bulk_mark_audits_only_the_rows_whose_mark_changes(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $unmarked = Hardware::create([
            'n_code' => $data['user']->n_code,
            'pc_name' => 'PC-UNMARKED',
            'type' => 'pc',
            'mark' => false,
        ]);

        $alreadyMarked = Hardware::create([
            'n_code' => $data['user']->n_code,
            'pc_name' => 'PC-ALREADY-MARKED',
            'type' => 'pc',
            'mark' => true,
        ]);

        $response = $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$unmarked->id, $alreadyMarked->id],
            'mark' => true,
        ])->assertOk();

        // The response count keeps its "matched rows" semantics (#1670).
        $response->assertJsonPath('count', 2);

        $audits = HardwareAudit::query()
            ->where('hardware_id', $unmarked->id)
            ->where('action', 'bulk_mark')
            ->get();

        $this->assertCount(1, $audits);
        $this->assertCount(1, $audits->first()->changes);
        $this->assertSame('mark', $audits->first()->changes[0]['field']);
        $this->assertFalse($audits->first()->changes[0]['old']);
        $this->assertTrue($audits->first()->changes[0]['new']);

        $this->assertSame(0, HardwareAudit::query()
            ->where('hardware_id', $alreadyMarked->id)
            ->where('action', 'bulk_mark')
            ->count(), 'the row already at the target value must not be audited');
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
