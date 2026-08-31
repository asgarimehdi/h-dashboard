<?php

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @covers \App\Models\HardwareAudit */

uses(TestCase::class, RefreshDatabase::class);

function makeAuditLivewireUser(): array
{
    $unit = Unit::create(['name' => 'Test Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    // Ensure permission exists (seeded by PermissionSeeder)
    Permission::firstOrCreate(['name' => 'manage_hardware']);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    $hardware = Hardware::create([
        'n_code' => $nCode,
        'pc_name' => 'LW-PC-001',
        'type' => 'pc',
        'cpu' => 'Intel i5',
        'ram' => '8192',
    ]);

    return [$user, $hardware];
}

it('hardware page renders with unified audit history modal', function () {
    [$user] = makeAuditLivewireUser();

    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->assertSee('شناسنامه سخت افزار');
});

it('loadHistory populates the history array from hardware_audits', function () {
    [$user, $hardware] = makeAuditLivewireUser();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hardware->id)
        ->assertSet('showHistoryModal', true)
        ->assertSet('historyHardwareId', $hardware->id);

    $history = $component->get('history');
    $this->assertNotEmpty($history);
    $this->assertEquals('created', $history[0]['action']);
    $this->assertArrayHasKey('source', $history[0]);
});

it('filterHistory filters by action', function () {
    [$user, $hardware] = makeAuditLivewireUser();
    $hardware->update(['cpu' => 'Intel i7']); // adds 'updated' audit

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hardware->id)
        ->call('filterHistory', 'updated');

    $history = $component->get('history');
    $this->assertNotEmpty($history);
    foreach ($history as $entry) {
        $this->assertEquals('updated', $entry['action']);
    }
});

it('rollbackHistoryField restores field value and logs rollback', function () {
    [$user, $hardware] = makeAuditLivewireUser();

    // Change cpu so an 'updated' audit exists with old='Intel i5'
    $hardware->update(['cpu' => 'Intel i7']);
    $audit = HardwareAudit::where('hardware_id', $hardware->id)
        ->where('action', 'updated')
        ->first();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hardware->id)
        ->call('rollbackHistoryField', $audit->id, 'cpu')
        ->assertHasNoErrors();

    // Hardware cpu is restored
    $this->assertDatabaseHas('hardwares', [
        'id' => $hardware->id,
        'cpu' => 'Intel i5',
    ]);

    // A rollback audit entry was created
    $this->assertDatabaseHas('hardware_audits', [
        'hardware_id' => $hardware->id,
        'action' => 'rollback',
    ]);
});

it('rollbackHistoryField errors on invalid audit id', function () {
    [$user, $hardware] = makeAuditLivewireUser();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hardware->id)
        ->call('rollbackHistoryField', 999999, 'cpu');

    // No crash; history still present
    $component->assertSet('showHistoryModal', true);
});

it('bulkMark keeps selection so unmark stays enabled', function () {
    [$user, $hardware] = makeAuditLivewireUser();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->set('selected', [$hardware->id])
        ->call('bulkMark', true);

    // Selection must be preserved after marking
    $this->assertEquals([$hardware->id], $component->get('selected'));
    // Hardware is marked
    $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'mark' => true]);

    // User can now unmark immediately without re-selecting
    $component->call('bulkMark', false);
    $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'mark' => false]);
});

it('clearSelection empties the selected array', function () {
    [$user, $hardware] = makeAuditLivewireUser();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->set('selected', [$hardware->id])
        ->call('clearSelection');

    $this->assertEquals([], $component->get('selected'));
});
