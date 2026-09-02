<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Observers\HardwareAuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

covers(HardwareAuditObserver::class);

class HardwareAuditObserverRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;

    protected $eId;

    protected $sId;

    protected $rId;

    protected $unit;

    protected $user;

    protected $nCode;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $this->nCode = (string) fake()->unique()->numerify('##########');
        $this->unit = Unit::create(['name' => 'Req Unit']);
        Person::create([
            'n_code' => $this->nCode,
            'f_name' => 'Req',
            'l_name' => 'Test',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create([
            'n_code' => $this->nCode,
            'password' => Hash::make('password'),
        ]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Permission::firstOrCreate(['name' => 'manage_hardware']);
        $this->user->givePermissionTo('manage_hardware');
        Session::put('current_unit_id', $this->unit->id);
    }

    public function test_audit_captures_ip_from_test_request(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/hardware', [
            'n_code' => $this->nCode,
            'pc_name' => 'REQ-PC-001',
            'type' => 'pc',
        ]);

        $response->assertCreated();

        $hardware = Hardware::where('pc_name', 'REQ-PC-001')->first();
        $this->assertNotNull($hardware);

        $audit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('127.0.0.1', $audit->ip_address);
    }

    public function test_audit_captures_user_agent_from_request(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/hardware', [
            'n_code' => $this->nCode,
            'pc_name' => 'REQ-PC-002',
            'type' => 'pc',
        ], [
            'User-Agent' => 'FlutterTest/1.0',
        ]);

        $response->assertCreated();

        $hardware = Hardware::where('pc_name', 'REQ-PC-002')->first();
        $audit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('FlutterTest', $audit->user_agent);
    }

    public function test_rollback_audit_captures_ip_from_request(): void
    {
        $this->actingAs($this->user, 'sanctum');

        // Create hardware first
        $createResponse = $this->postJson('/api/hardware', [
            'n_code' => $this->nCode,
            'pc_name' => 'REQ-PC-ROLLBACK',
            'type' => 'pc',
            'cpu' => 'Intel i5',
        ]);
        $createResponse->assertCreated();

        $hardware = Hardware::where('pc_name', 'REQ-PC-ROLLBACK')->first();

        // Update it to create an audit trail
        $updateResponse = $this->patchJson("/api/hardware/{$hardware->id}", [
            'cpu' => 'Intel i7',
        ]);
        $updateResponse->assertOk();

        $updateAudit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'updated')
            ->first();
        $this->assertNotNull($updateAudit);

        // Rollback via API
        $rollbackResponse = $this->postJson(
            "/api/hardware/{$hardware->id}/audits/{$updateAudit->id}/rollback",
            ['field' => 'cpu'],
            ['User-Agent' => 'RollbackTest/2.0']
        );
        $rollbackResponse->assertOk();

        $rollbackAudit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'rollback')
            ->first();

        $this->assertNotNull($rollbackAudit);
        $this->assertEquals('127.0.0.1', $rollbackAudit->ip_address);
        $this->assertStringContainsString('RollbackTest', $rollbackAudit->user_agent);
    }
}
