<?php

use App\Http\Controllers\Api\HardwareController;
use App\Models\Hardware;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_bulk_methods_use_try_finally_for_suppress_audit(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Api/HardwareController.php'));
        expect($src)->toContain("request()->attributes->set('suppress_audit', true);");
        expect($src)->toContain('} finally {');
        expect(substr_count($src, "request()->attributes->remove('suppress_audit');"))->toBe(2);
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
