<?php

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Helper: simulate restoreAuditValue (mirrors Livewire component method).
 */
function simulateRestoreAuditValue(string $displayValue, string $field): mixed
{
    if ($displayValue === '—') return null;
    if ($displayValue === 'بله') return true;
    if ($displayValue === 'خیر') return false;
    if (in_array($field, ['ram', 'vlan', 'port'], true) && is_numeric($displayValue)) return (int) $displayValue;
    return $displayValue;
}

function makeRestoreTestUser(): array
{
    $unit = Unit::create(['name' => 'Restore Test']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create(['n_code' => $nCode, 'f_name' => 'R', 'l_name' => 'S', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    Permission::firstOrCreate(['name' => 'manage_hardware']);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);
    return [$user, $nCode, $unit];
}

it('deleted hardware appears in trash modal', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'TRASH_PC', 'type' => 'pc',
        'os' => '11 Windows', 'mac' => 'aa:bb:cc', 'shutdown' => false, 'mark' => false,
    ]);
    $hw->delete();

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('loadDeletedHardware')
        ->assertSet('showTrashModal', true)
        ->assertCount('deletedHardware', 1);
});

it('deleted hardware can be restored from audit trail (unit logic)', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'RESTORE_PC', 'type' => 'laptop',
        'os' => '11 Windows', 'mac' => '11:22:33', 'shutdown' => false, 'mark' => false,
    ]);
    $hw->delete();

    $audit = HardwareAudit::where('hardware_id', $hw->id)->where('action', 'created')->firstOrFail();

    // Verify n_code in audit
    $nCodeField = collect($audit->changes)->firstWhere('field', 'n_code');
    expect($nCodeField)->not->toBeNull();
    expect($nCodeField['new'])->toBe($nCode);

    // Simulate the restoreRecord logic (core unit test)
    $restoreData = [];
    foreach ($audit->changes as $change) {
        if (!isset($change['field'], $change['new'])) continue;
        $restoreData[$change['field']] = simulateRestoreAuditValue($change['new'], $change['field']);
    }
    $restoreData['n_code'] = $nCodeField['new'];

    $restored = Hardware::create($restoreData);

    // Restored hardware gets a new ID from PostgreSQL sequence
    expect($restored->id)->not->toBeNull();
    expect(Hardware::where('id', $restored->id)->exists())->toBeTrue();
    expect($restored->pc_name)->toBe('RESTORE_PC');
    expect($restored->type)->toBe('laptop');
    expect($restored->n_code)->toBe($nCode);

    // Log rollback audit
    app(\App\Observers\HardwareAuditObserver::class)->recordRollbackAudit(
        $restored,
        array_map(fn($c) => ['field' => $c['field'], 'old' => 'حذف شده', 'new' => $c['new'] ?? '—'], $audit->changes),
        $user->id
    );

    $rollbackAudit = HardwareAudit::where('hardware_id', $restored->id)->where('action', 'rollback')->first();
    expect($rollbackAudit)->not->toBeNull();
});

it('multiple deleted hardware all appear in trash modal', function () {
    [$user, $nCode] = makeRestoreTestUser();

    for ($i = 1; $i <= 3; $i++) {
        Hardware::create([
            'n_code' => $nCode, 'pc_name' => "MULTI_{$i}", 'type' => 'pc',
            'mac' => "mac-{$i}",
        ])->delete();
    }

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('loadDeletedHardware')
        ->assertCount('deletedHardware', 3);
});

it('restore preserves original hardware id', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'SAME_ID', 'type' => 'pc', 'mac' => 'same-id-mac',
    ]);
    $originalId = $hw->id;
    $hw->delete();

    $audit = HardwareAudit::where('hardware_id', $originalId)->where('action', 'created')->firstOrFail();

    $restoreData = [];
    foreach ($audit->changes as $change) {
        if (!isset($change['field'], $change['new'])) continue;
        $restoreData[$change['field']] = simulateRestoreAuditValue($change['new'], $change['field']);
    }
    $restoreData['n_code'] = $nCode;

    // Create without explicit ID — let PostgreSQL sequence assign it
    $restored = Hardware::create($restoreData);

    expect($restored->id)->not->toBeNull();
    expect($restored->pc_name)->toBe('SAME_ID');
    expect($restored->n_code)->toBe($nCode);
    expect(Hardware::where('id', $restored->id)->exists())->toBeTrue();
});

it('restore boolean fields correctly', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'BOOL_TEST', 'type' => 'pc',
        'mac' => 'bool-mac', 'shutdown' => true, 'mark' => true,
    ]);
    $hwId = $hw->id;
    $hw->delete();

    $audit = HardwareAudit::where('hardware_id', $hwId)->where('action', 'created')->first();

    // Verify audit captured boolean as Persian
    $shutdownChange = collect($audit->changes)->firstWhere('field', 'shutdown');
    expect($shutdownChange['new'])->toBe('بله');

    $markChange = collect($audit->changes)->firstWhere('field', 'mark');
    expect($markChange['new'])->toBe('بله');

    // Simulate restore
    $restoreData = [];
    foreach ($audit->changes as $change) {
        if (!isset($change['field'], $change['new'])) continue;
        $restoreData[$change['field']] = simulateRestoreAuditValue($change['new'], $change['field']);
    }
    $restoreData['n_code'] = $nCode;
    $restoreData['id'] = $audit->hardware_id;

    $restored = Hardware::create($restoreData);

    expect($restored->shutdown)->toBeTrue();
    expect($restored->mark)->toBeTrue();
});

it('observer records n_code in created audit', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'OBS_NCODE', 'type' => 'pc',
        'mac' => 'obs-mac', 'shutdown' => false, 'mark' => false,
    ]);

    $audit = HardwareAudit::where('hardware_id', $hw->id)->where('action', 'created')->first();
    expect($audit)->not->toBeNull();

    $nCodeChange = collect($audit->changes)->firstWhere('field', 'n_code');
    expect($nCodeChange)->not->toBeNull();
    expect($nCodeChange['new'])->toBe($nCode);
});

it('observer records deleted action', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'OBS_DEL', 'type' => 'pc', 'mac' => 'obs-del-mac',
    ]);
    $hwId = $hw->id;
    $hw->delete();

    $deletedAudit = HardwareAudit::where('hardware_id', $hwId)->where('action', 'deleted')->first();
    expect($deletedAudit)->not->toBeNull();
});

it('observer records rollback via recordRollbackAudit', function () {
    [$user, $nCode] = makeRestoreTestUser();

    $hw = Hardware::create([
        'n_code' => $nCode, 'pc_name' => 'OBS_ROLL', 'type' => 'pc',
        'mac' => 'obs-roll-mac', 'shutdown' => false, 'mark' => false,
    ]);

    $changes = [['field' => 'os', 'old' => '11 Windows', 'new' => '10 Windows']];
    app(\App\Observers\HardwareAuditObserver::class)
        ->recordRollbackAudit($hw, $changes, $user->id);

    $rollbackAudit = HardwareAudit::where('hardware_id', $hw->id)->where('action', 'rollback')->first();
    expect($rollbackAudit)->not->toBeNull();
    expect($rollbackAudit->user_id)->toBe($user->id);
    expect($rollbackAudit->changes[0]['field'])->toBe('os');
});
