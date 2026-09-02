<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareAuditController;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

covers(HardwareAuditController::class);

class HardwareAuditRollbackAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;

    protected $eId;

    protected $sId;

    protected $rId;

    protected $unit;

    protected $user;

    protected $hardware;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        $this->unit = Unit::create(['name' => 'Test Unit']);
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'Test',
            'l_name' => 'User',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Permission::firstOrCreate(['name' => 'manage_hardware']);
        $this->user->givePermissionTo('manage_hardware');
        Session::put('current_unit_id', $this->unit->id);

        $this->actingAs($this->user);

        $this->hardware = Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'TEST-PC-001',
            'type' => 'pc',
            'os' => 'Windows 11',
            'cpu' => 'Intel i5',
            'ram' => '8192',
            'hdd' => 'SSD 256GB',
            'mark' => false,
            'shutdown' => false,
        ]);
    }

    private function authHeaders(): array
    {
        $token = $this->user->createToken('test')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_rollback_rejects_non_fillable_field_id(): void
    {
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
                'field' => 'id',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('hardwares', [
            'id' => $this->hardware->id,
            'n_code' => $this->hardware->n_code,
        ]);
    }

    public function test_rollback_rejects_non_fillable_field_created_at(): void
    {
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
                'field' => 'created_at',
            ]);

        $response->assertStatus(422);
    }

    public function test_rollback_n_code_to_out_of_scope_person_is_forbidden(): void
    {
        // Create an unrelated unit and person
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        $otherNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $otherNCode,
            'f_name' => 'Other',
            'l_name' => 'Person',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $otherUnit->id,
        ]);

        // Audit: old=out-of-scope, new=current → rollback restores old (out-of-scope) → should fail
        $audit = HardwareAudit::create([
            'hardware_id' => $this->hardware->id,
            'user_id' => $this->user->id,
            'action' => 'updated',
            'changes' => [['field' => 'n_code', 'old' => $otherNCode, 'new' => $this->hardware->n_code]],
            'source' => 'web',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
                'field' => 'n_code',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('hardwares', [
            'id' => $this->hardware->id,
            'n_code' => $this->hardware->n_code,
        ]);
    }

    public function test_rollback_n_code_to_in_scope_person_succeeds(): void
    {
        // Create another person in the same unit
        $newNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $newNCode,
            'f_name' => 'New',
            'l_name' => 'Person',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);

        // Audit: old=in-scope person, new=current → rollback restores old (in-scope) → should succeed
        $audit = HardwareAudit::create([
            'hardware_id' => $this->hardware->id,
            'user_id' => $this->user->id,
            'action' => 'updated',
            'changes' => [['field' => 'n_code', 'old' => $newNCode, 'new' => $this->hardware->n_code]],
            'source' => 'web',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
                'field' => 'n_code',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('hardwares', [
            'id' => $this->hardware->id,
            'n_code' => $newNCode,
        ]);
        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $this->hardware->id,
            'action' => 'rollback',
        ]);
    }

    public function test_rollback_valid_fillable_field_succeeds(): void
    {
        $this->hardware->update(['mark' => true]);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
                'field' => 'mark',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('hardwares', [
            'id' => $this->hardware->id,
            'mark' => false,
        ]);
    }
}
