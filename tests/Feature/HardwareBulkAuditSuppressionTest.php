<?php

use App\Http\Controllers\Api\HardwareController;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareController::class);

class HardwareBulkAuditSuppressionTest extends TestCase
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

        return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode, 'hardware' => [$hardware1, $hardware2]];
    }

    /**
     * #1026: behavioural replacement for the old source-text counter test
     * (`substr_count($controller, "remove('suppress_audit')") === 2`). That
     * test punished any healthy refactor — including this issue's move of
     * `batchInsertAudits()` out of the controller — without actually exercising
     * the flag. This version runs a bulk delete through BOTH paths (API and the
     * Livewire index, which was uncovered before #1026) and asserts the flag is
     * restored to false and each device carries exactly one audit row.
     */
    public function test_bulk_methods_restore_suppress_audit_and_write_no_duplicate_audit(): void
    {
        $this->assertFalse(Hardware::$suppressAudit);

        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        // --- API path -------------------------------------------------------
        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        $this->assertFalse(Hardware::$suppressAudit, 'bulk-mark must restore the suppress flag');
        $this->assertSame(
            1,
            HardwareAudit::where('hardware_id', $data['hardware'][0]->id)
                ->where('action', 'bulk_mark')
                ->count(),
            'bulk-mark must write exactly one bulk_mark row'
        );

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$data['hardware'][1]->id],
        ])->assertOk();

        $this->assertFalse(Hardware::$suppressAudit, 'bulk-delete must restore the suppress flag');
        $this->assertSame(
            1,
            HardwareAudit::where('hardware_id', $data['hardware'][1]->id)
                ->whereIn('action', HardwareAudit::DELETING_ACTIONS)
                ->count(),
            'bulk-delete must write exactly one deleting row'
        );

        // --- Web path (Livewire component) ----------------------------------
        $web = Hardware::create([
            'n_code' => $data['n_code'],
            'pc_name' => 'PC-WEB-SUPPRESS',
            'type' => 'pc',
        ]);

        Livewire::test('hardware.index')
            ->set('selected', [$web->id])
            ->call('bulkDelete');

        $this->assertFalse(Hardware::$suppressAudit, 'web bulk delete must restore the suppress flag');
        $this->assertSame(
            1,
            HardwareAudit::where('hardware_id', $web->id)
                ->whereIn('action', HardwareAudit::DELETING_ACTIONS)
                ->count(),
            'web bulk delete must write exactly one deleting row'
        );
    }

    public function test_suppress_audit_is_false_after_bulk_mark(): void
    {
        expect(Hardware::$suppressAudit)->toBeFalse();

        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        expect(Hardware::$suppressAudit)->toBeFalse();
    }

    public function test_suppress_audit_is_false_after_bulk_delete(): void
    {
        expect(Hardware::$suppressAudit)->toBeFalse();

        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$data['hardware'][0]->id],
        ])->assertOk();

        expect(Hardware::$suppressAudit)->toBeFalse();
    }

    public function test_audit_still_records_after_bulk_mark(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        // Perform bulk mark
        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        // Verify the bulk_mark audit was recorded
        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $data['hardware'][0]->id,
            'action' => 'bulk_mark',
            'source' => 'bulk',
        ]);

        // Now create a new hardware — observer should fire (suppressAudit is false)
        $nCode = $data['n_code'];
        $newHw = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-NEW', 'type' => 'pc']);

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $newHw->id,
            'action' => 'created',
        ]);
    }

    public function test_request_attribute_suppress_audit_prevents_audit_logging(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        // Set request attribute
        request()->attributes->set('suppress_audit', true);

        // Create hardware (should not log audit)
        $hardware = Hardware::create(['n_code' => $data['n_code'], 'pc_name' => 'PC-SUPPRESSED', 'type' => 'pc']);

        // Verify no audit entry
        $this->assertDatabaseMissing('hardware_audits', [
            'hardware_id' => $hardware->id,
            'action' => 'created',
        ]);

        // Cleanup
        request()->attributes->remove('suppress_audit');
    }
}
