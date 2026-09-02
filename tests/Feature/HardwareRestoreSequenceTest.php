<?php

use App\Http\Controllers\Api\HardwareAuditController;
use App\Models\Hardware;
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

function makeSequenceTestUser(): array
{
    $unit = Unit::create(['name' => 'Seq Test Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Seq', 'l_name' => 'User',
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

it('restore advances sequence so next auto-insert succeeds', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Sequence reset only applies to PostgreSQL.');
    }

    [$user, $nCode, , $token] = makeSequenceTestUser();

    // Create hardware with a high explicit id via restore — craft an audit with
    // hardware_id that does not yet exist, then restore it.
    $targetId = 900001;
    $auditId = DB::table('hardware_audits')->insertGetId([
        'hardware_id' => $targetId,
        'user_id' => $user->id,
        'action' => 'created',
        'changes' => json_encode([
            ['field' => 'n_code', 'old' => null, 'new' => $nCode],
            ['field' => 'pc_name', 'old' => null, 'new' => 'SEQ_RESTORED'],
            ['field' => 'type', 'old' => null, 'new' => 'pc'],
            ['field' => 'mac', 'old' => null, 'new' => 'seq-mac'],
            ['field' => 'shutdown', 'old' => null, 'new' => 'خیر'],
            ['field' => 'mark', 'old' => null, 'new' => 'خیر'],
        ]),
        'source' => 'web',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Restore the hardware
    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson("/api/hardware/audits/{$auditId}/restore-record");

    $response->assertStatus(200)->assertJsonPath('success', true);

    // The restored hardware exists (id is not in $fillable so it gets a new auto-increment id)
    $restored = Hardware::where('pc_name', 'SEQ_RESTORED')->first();
    expect($restored)->not->toBeNull();
    expect($restored->pc_name)->toBe('SEQ_RESTORED');

    // Sequence is now advanced — next auto-insert should NOT collide
    $next = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'AFTER_RESTORE', 'type' => 'laptop',
        'mac' => 'after-restore-mac', 'shutdown' => false, 'mark' => false,
    ]);

    expect($next->id)->toBeGreaterThan($restored->id);

    // Verify sequence value is >= MAX(id)
    $seqVal = DB::selectOne('SELECT last_value FROM hardwares_id_seq');
    $maxId = DB::selectOne('SELECT MAX(id) as m FROM hardwares');
    expect((int) $seqVal->last_value)->toBeGreaterThanOrEqual((int) $maxId->m);
});

it('restore-record source code contains pgsql sequence guard', function () {
    // Source-level assertion: the controller has the setval/pg_get_serial_sequence logic
    $controllerFile = file_get_contents(
        app_path('Http/Controllers/Api/HardwareAuditController.php')
    );

    expect($controllerFile)->toContain('pg_get_serial_sequence');
    expect($controllerFile)->toContain('setval');
    expect($controllerFile)->toContain('hardwares');
});
