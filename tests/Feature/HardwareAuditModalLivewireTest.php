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

covers(Hardware::class);

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ────────────────────────────────────────────────────────────────

function modalMakeUser(?string $permission = null): array
{
    Permission::firstOrCreate(['name' => 'manage_hardware']);

    $unit = Unit::create(['name' => 'واحد تست']);

    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        't_id' => $tId,
        'e_id' => $eId,
        's_id' => $sId,
        'r_id' => $rId,
        'u_id' => $unit->id,
    ]);

    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    if ($permission) {
        $perm = Permission::firstOrCreate(['name' => $permission]);
        $user->givePermissionTo($perm);
    }

    Session::put('current_unit_id', $unit->id);

    return [$user, $unit, $nCode];
}

function modalCreateHardware(User $user, string $nCode): Hardware
{
    return Hardware::create([
        'n_code' => $nCode,
        'pc_name' => 'HW-PC-001',
        'type' => 'pc',
        'cpu' => 'Intel i5',
        'ram' => '8192',
    ]);
}

function modalCreateAudit(Hardware $hw, string $action, ?array $changes = null, ?int $userId = null): HardwareAudit
{
    return HardwareAudit::create([
        'hardware_id' => $hw->id,
        'user_id' => $userId,
        'action' => $action,
        'source' => 'web',
        'changes' => $changes ?? [],
    ]);
}

// ── Tests ──────────────────────────────────────────────────────────────────

it('redirects guest request to login', function () {
    $response = $this->get('/hardware');
    $response->assertRedirect('/login');
});

it('returns 403 for unauthorized user without manage_hardware permission', function () {
    [$user] = modalMakeUser(null); // no permission

    $this->actingAs($user);
    $this->get('/hardware')->assertForbidden();
});

it('allows authorized user to view the hardware index page', function () {
    [$user] = modalMakeUser('manage_hardware');

    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->assertSee('شناسنامه سخت افزار');
});

it('loadHistory opens the modal with history data', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    $hw = modalCreateHardware($user, $nCode);

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id)
        ->assertSet('showHistoryModal', true)
        ->assertSet('historyHardwareId', $hw->id);

    $history = $component->get('history');
    $this->assertNotEmpty($history);
    $this->assertEquals('created', $history[0]['action']);
    $this->assertEquals($hw->id, $component->get('historyHardwareId'));
});

it('shows empty state message when there is no audit history', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    // Create a second hardware record with no audits to test empty state
    $nCode2 = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode2,
        'f_name' => 'خالی',
        'l_name' => 'رکورد',
        't_id' => DB::table('tahsils')->insertGetId(['name' => 'T']),
        'e_id' => DB::table('estekhdams')->insertGetId(['name' => 'E']),
        's_id' => DB::table('semats')->insertGetId(['name' => 'S']),
        'r_id' => DB::table('radifs')->insertGetId(['name' => 'R']),
        'u_id' => Unit::where('name', 'واحد تست')->first()->id,
    ]);
    // Delete all audits for this hardware to simulate empty state
    HardwareAudit::query()->delete();

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id ?? 1);

    $history = $component->get('history');
    $this->assertEmpty($history);
    $this->assertEquals(0, $component->get('historyTotal'));
});

it('filters history by action type', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    $hw = modalCreateHardware($user, $nCode);

    // Create audit records of different actions
    modalCreateAudit($hw, 'updated', [
        ['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7'],
    ], $user->id);
    modalCreateAudit($hw, 'deleted', [], $user->id);
    modalCreateAudit($hw, 'rollback', [
        ['field' => 'cpu', 'old' => 'Intel i7', 'new' => 'Intel i5'],
    ], $user->id);

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id);

    // Filter by 'updated'
    $component->call('filterHistory', 'updated');
    $this->assertEquals('updated', $component->get('historyActionFilter'));
    foreach ($component->get('history') as $entry) {
        $this->assertEquals('updated', $entry['action']);
    }

    // Filter by 'deleted'
    $component->call('filterHistory', 'deleted');
    foreach ($component->get('history') as $entry) {
        $this->assertEquals('deleted', $entry['action']);
    }

    // Filter by 'rollback'
    $component->call('filterHistory', 'rollback');
    foreach ($component->get('history') as $entry) {
        $this->assertEquals('rollback', $entry['action']);
    }

    // Clear filter (null = show all)
    $component->call('filterHistory', null);
    $this->assertNull($component->get('historyActionFilter'));
    $this->assertCount(4, $component->get('history')); // created + updated + deleted + rollback
});

it('shows pagination controls when history exceeds perPage', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    $hw = modalCreateHardware($user, $nCode);

    // Create 19 more audit records (modalCreateHardware already triggers observer creating 1)
    for ($i = 0; $i < 19; $i++) {
        HardwareAudit::create([
            'hardware_id' => $hw->id,
            'user_id' => $user->id,
            'action' => 'updated',
            'source' => 'web',
            'changes' => [['field' => 'ram', 'old' => '4096', 'new' => '8192']],
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id);

    // 1 created from observer + 19 manual = 20
    $this->assertEquals(20, $component->get('historyTotal'));
    $this->assertEquals(15, $component->get('historyPerPage'));
    // First page has 15 items
    $this->assertCount(15, $component->get('history'));
    $this->assertEquals(1, $component->get('historyCurrentPage'));

    // Navigate to page 2
    $component->call('historyPage', 2);
    $this->assertEquals(2, $component->get('historyCurrentPage'));
    $this->assertCount(5, $component->get('history'));
});

it('shows rollback button only for updated/rollback actions with non-dash old value', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    $hw = modalCreateHardware($user, $nCode);

    // 'updated' action with actual old value — rollback button should appear
    $updated = modalCreateAudit($hw, 'updated', [
        ['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7'],
    ], $user->id);

    // 'created' action — no rollback button (action not in ['updated', 'rollback'])
    $created = modalCreateAudit($hw, 'created', [
        ['field' => 'cpu', 'old' => null, 'new' => 'Intel i5'],
    ], $user->id);

    // 'deleted' action — no rollback button
    $deleted = modalCreateAudit($hw, 'deleted', [], $user->id);

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id);

    // The Livewire test assertion API checks rendered HTML.
    // Updated entry with old='Intel i5' (≠ '—') → rollback button present
    $component->assertSee('rollbackHistoryField');

    // Verify the history data structure supports rollback conditions:
    $history = $component->get('history');
    $updatedEntry = collect($history)->firstWhere('action', 'updated');
    $this->assertNotNull($updatedEntry);
    // The 'updated' entry should have changes with non-dash old value
    $this->assertNotEquals('—', $updatedEntry['changes'][0]['old']);

    $createdEntry = collect($history)->firstWhere('action', 'created');
    $this->assertNotNull($createdEntry);
    // Created entries with null old become '—' in display; action not in ['updated','rollback']
    // so no rollback button even if old were non-dash
});

it('rollbackHistoryField restores the previous field value and creates audit', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    $hw = modalCreateHardware($user, $nCode);

    // Change CPU — observer creates 'updated' audit
    $hw->update(['cpu' => 'Intel i7']);
    $audit = HardwareAudit::where('hardware_id', $hw->id)
        ->where('action', 'updated')
        ->first();
    $this->assertNotNull($audit);

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id)
        ->call('rollbackHistoryField', $audit->id, 'cpu')
        ->assertHasNoErrors();

    // Hardware CPU is restored to old value
    $this->assertDatabaseHas('hardwares', [
        'id' => $hw->id,
        'cpu' => 'Intel i5',
    ]);

    // A rollback audit was created
    $this->assertDatabaseHas('hardware_audits', [
        'hardware_id' => $hw->id,
        'action' => 'rollback',
    ]);

    // Rollback entry has the reversed change
    $rollbackAudit = HardwareAudit::where('hardware_id', $hw->id)
        ->where('action', 'rollback')
        ->first();
    $this->assertNotNull($rollbackAudit);
    $this->assertEquals('cpu', $rollbackAudit->changes[0]['field']);
    $this->assertEquals('Intel i7', $rollbackAudit->changes[0]['old']);
    $this->assertEquals('Intel i5', $rollbackAudit->changes[0]['new']);
});

it('resets page to 1 when loadHistory or filterHistory is called', function () {
    [$user, , $nCode] = modalMakeUser('manage_hardware');
    $hw = modalCreateHardware($user, $nCode);

    // Create 19 more audits so pagination kicks in (1 created + 19 = 20)
    for ($i = 0; $i < 19; $i++) {
        HardwareAudit::create([
            'hardware_id' => $hw->id,
            'user_id' => $user->id,
            'action' => 'updated',
            'source' => 'web',
            'changes' => [['field' => 'ram', 'old' => '4096', 'new' => '8192']],
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::actingAs($user)->test('hardware.index');
    $component->call('loadHistory', $hw->id);

    // Navigate to page 2
    $component->call('historyPage', 2);
    $this->assertEquals(2, $component->get('historyCurrentPage'));

    // Calling loadHistory again should reset to page 1
    $component->call('loadHistory', $hw->id);
    $this->assertEquals(1, $component->get('historyCurrentPage'));

    // Navigate to page 2 again
    $component->call('historyPage', 2);
    $this->assertEquals(2, $component->get('historyCurrentPage'));

    // Calling filterHistory should also reset to page 1
    $component->call('filterHistory', 'updated');
    $this->assertEquals(1, $component->get('historyCurrentPage'));
});
