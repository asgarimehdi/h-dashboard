<?php

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

uses(TestCase::class, RefreshDatabase::class);

function makeScopeTestUser(): array
{
    $unit = Unit::create(['name' => 'Scope Test Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Scope', 'l_name' => 'User',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
        'u_id' => $unit->id,
    ]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    Permission::firstOrCreate(['name' => 'manage_hardware']);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $nCode, $unit, $token];
}

it('restore denied when audit changes has no n_code', function () {
    [$user, , $unit, $token] = makeScopeTestUser();

    // Insert an audit directly — bypass observer so we can craft missing n_code
    $auditId = DB::table('hardware_audits')->insertGetId([
        'hardware_id' => 999901,
        'user_id' => $user->id,
        'action' => 'created',
        'changes' => json_encode([
            ['field' => 'pc_name', 'old' => null, 'new' => 'NO_NCODE_PC'],
            ['field' => 'type', 'old' => null, 'new' => 'pc'],
            // no n_code field
        ]),
        'source' => 'web',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson("/api/hardware/audits/{$auditId}/restore-record");

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Hardware record not accessible.');
});

it('restore denied when audit changes is null', function () {
    [$user, , , $token] = makeScopeTestUser();

    $auditId = DB::table('hardware_audits')->insertGetId([
        'hardware_id' => 999902,
        'user_id' => $user->id,
        'action' => 'created',
        'changes' => null,
        'source' => 'web',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson("/api/hardware/audits/{$auditId}/restore-record");

    // The 422 guard catches null changes before scope check
    $response->assertStatus(422)
        ->assertJsonPath('message', 'No change data in audit to restore from.');
});

it('restore denied when audit n_code points to out-of-scope unit', function () {
    [$user, , , $token] = makeScopeTestUser();

    // Create a person in a DIFFERENT unit
    $otherUnit = Unit::create(['name' => 'Other Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $outOfScopeNCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $outOfScopeNCode, 'f_name' => 'Out', 'l_name' => 'Scope',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
        'u_id' => $otherUnit->id,
    ]);

    // Create a hardware in the OTHER unit, delete it, then try to restore
    $hw = Hardware::create([
        'n_code' => $outOfScopeNCode, 'pc_name' => 'OTHER_HW', 'type' => 'laptop',
        'mac' => 'out-of-scope-mac', 'shutdown' => false, 'mark' => false,
    ]);
    $hw->delete();

    $audit = HardwareAudit::where('hardware_id', $hw->id)
        ->where('action', 'created')->firstOrFail();

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson("/api/hardware/audits/{$audit->id}/restore-record");

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Hardware record not accessible.');
});

it('restore succeeds when audit n_code is in-scope', function () {
    [$user, $nCode, , $token] = makeScopeTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'INSOPE_HW', 'type' => 'pc',
        'mac' => 'in-scope-mac', 'shutdown' => false, 'mark' => false,
    ]);
    $hw->delete();

    $audit = HardwareAudit::where('hardware_id', $hw->id)
        ->where('action', 'created')->firstOrFail();

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson("/api/hardware/audits/{$audit->id}/restore-record");

    $response->assertStatus(200)->assertJsonPath('success', true);
    // id is not in $fillable, so Hardware::create() assigns a new auto-increment id
    $this->assertDatabaseHas('hardwares', ['pc_name' => 'INSOPE_HW', 'n_code' => $nCode]);
});

it('restore still checks exists guard when hardware row still exists', function () {
    [$user, $nCode, , $token] = makeScopeTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'EXISTS_PC', 'type' => 'pc',
        'mac' => 'exists-mac', 'shutdown' => false, 'mark' => false,
    ]);

    $audit = HardwareAudit::where('hardware_id', $hw->id)
        ->where('action', 'created')->firstOrFail();

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson("/api/hardware/audits/{$audit->id}/restore-record");

    $response->assertStatus(422)
        ->assertJsonPath('message', 'This hardware record still exists — use rollback instead.');
});
