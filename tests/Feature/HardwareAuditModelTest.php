<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HardwareAuditModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
    }

    protected function createHardware(): Hardware
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        return Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'PC-001',
            'type' => 'Desktop',
        ]);
    }

    // --- Relationships ---

    public function test_audit_belongs_to_hardware(): void
    {
        $hardware = $this->createHardware();

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'action' => 'created',
            'changes' => ['pc_name' => 'PC-001'],
            'source' => 'web',
        ]);

        $this->assertNotNull($audit->hardware);
        $this->assertEquals($hardware->id, $audit->hardware->id);
    }

    public function test_audit_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $hardware = $this->createHardware();

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'user_id' => $user->id,
            'action' => 'updated',
            'changes' => [],
            'source' => 'web',
        ]);

        $this->assertNotNull($audit->user);
        $this->assertEquals($user->id, $audit->user->id);
    }

    public function test_audit_user_is_nullable(): void
    {
        $hardware = $this->createHardware();

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'action' => 'created',
            'changes' => [],
            'source' => 'web',
        ]);

        $this->assertNull($audit->user_id);
        $this->assertNull($audit->user);
    }

    // --- Changes cast ---

    public function test_changes_is_cast_to_array(): void
    {
        $hardware = $this->createHardware();

        $changes = [
            ['field' => 'ram', 'old' => '8GB', 'new' => '16GB'],
            ['field' => 'hdd', 'old' => '256GB', 'new' => '512GB'],
        ];

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'action' => 'updated',
            'changes' => $changes,
            'source' => 'web',
        ]);

        $this->assertIsArray($audit->changes);
        $this->assertCount(2, $audit->changes);
        $this->assertEquals('ram', $audit->changes[0]['field']);
        $this->assertEquals('8GB', $audit->changes[0]['old']);
        $this->assertEquals('16GB', $audit->changes[0]['new']);
    }

    // --- Fillable ---

    public function test_audit_allows_mass_assignment(): void
    {
        $hardware = $this->createHardware();

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'action' => 'deleted',
            'changes' => ['pc_name' => 'PC-001'],
            'source' => 'api',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'TestAgent',
        ]);

        $this->assertEquals('deleted', $audit->action);
        $this->assertEquals('api', $audit->source);
        $this->assertEquals('192.168.1.1', $audit->ip_address);
        $this->assertEquals('TestAgent', $audit->user_agent);
    }

    // --- Audit survives hardware deletion ---

    public function test_audit_survives_hardware_deletion(): void
    {
        $hardware = $this->createHardware();
        $hardwareId = $hardware->id;

        HardwareAudit::create([
            'hardware_id' => $hardwareId,
            'action' => 'created',
            'changes' => ['pc_name' => 'PC-001'],
            'source' => 'web',
        ]);

        $countBefore = HardwareAudit::where('hardware_id', $hardwareId)->count();
        $this->assertGreaterThanOrEqual(1, $countBefore);

        // Delete hardware (no FK cascade on hardware_id)
        Hardware::where('id', $hardwareId)->forceDelete();

        $this->assertGreaterThanOrEqual($countBefore, HardwareAudit::where('hardware_id', $hardwareId)->count());
        $audit = HardwareAudit::where('hardware_id', $hardwareId)->first();
        $this->assertNotNull($audit);
    }

    // --- Action types ---

    public function test_audit_action_types(): void
    {
        $hardware = $this->createHardware();

        $actions = ['created', 'updated', 'deleted', 'bulk_mark', 'bulk_delete', 'rollback'];

        foreach ($actions as $action) {
            HardwareAudit::create([
                'hardware_id' => $hardware->id,
                'action' => $action,
                'changes' => [],
                'source' => 'web',
            ]);
        }

        // +1 for observer-created audit on Hardware::create()
        $this->assertDatabaseCount('hardware_audits', count($actions) + 1);
    }

    // --- Source tracking ---

    public function test_audit_source_values(): void
    {
        $hardware = $this->createHardware();

        $sources = ['web', 'api', 'import', 'bulk'];

        foreach ($sources as $source) {
            HardwareAudit::create([
                'hardware_id' => $hardware->id,
                'action' => 'updated',
                'changes' => [],
                'source' => $source,
            ]);
        }

        // +1 for observer-created audit on Hardware::create()
        $this->assertDatabaseCount('hardware_audits', count($sources) + 1);
    }

    // --- Timestamps ---

    public function test_audit_has_timestamps(): void
    {
        $hardware = $this->createHardware();

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'action' => 'created',
            'changes' => [],
            'source' => 'web',
        ]);

        $this->assertNotNull($audit->created_at);
        $this->assertNotNull($audit->updated_at);
    }
}
