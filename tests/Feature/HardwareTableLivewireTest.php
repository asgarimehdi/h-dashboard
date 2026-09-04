<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(Hardware::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
});

function makeHardwareUser(): array
{
    $unit = Unit::create(['name' => 'واحد تست']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        't_id' => 1,
        'e_id' => 1,
        's_id' => 1,
        'r_id' => 1,
        'u_id' => $unit->id,
    ]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->givePermissionTo('manage_hardware');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return [$user, $unit, $nCode];
}

function seedHardware(string $nCode, array $overrides = []): Hardware
{
    return Hardware::create(array_merge([
        'n_code' => $nCode,
        'pc_name' => 'Test-PC',
    ], $overrides));
}

// ==================== Auth / Smoke ====================

it('redirects guest to login', function () {
    $this->get('/hardware')->assertRedirect('/login');
});

it('returns 403 for unauthorized user', function () {
    $unit = Unit::create(['name' => 'واحد']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
        't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
    ]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    $this->actingAs($user);

    $this->get('/hardware')->assertStatus(403);
});

it('authorized user can view the hardware page', function () {
    [$user] = makeHardwareUser();
    $this->actingAs($user);

    Livewire::test('hardware.index')
        ->assertOk()
        ->assertSee('شناسنامه سخت افزار');
});

// ==================== Table Rendering ====================

it('renders both mobile and desktop layouts', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    seedHardware($nCode, ['pc_name' => 'PC-1', 'type' => 'pc']);
    seedHardware($nCode, ['pc_name' => 'PC-2', 'type' => 'laptop']);

    $component = Livewire::test('hardware.index');
    $component->assertSee('PC-1')
        ->assertSee('PC-2');

    $html = $component->html();
    $this->assertStringContainsString('md:hidden', $html);
    $this->assertStringContainsString('hidden md:block', $html);
});

it('displays status badges for mark, off, and active states', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    // mark=true → status 'mark' → "علامت" badge
    seedHardware($nCode, ['pc_name' => 'Marked-PC', 'mark' => true, 'shutdown' => false]);
    // shutdown=true → status 'off' → "خاموش" badge
    seedHardware($nCode, ['pc_name' => 'Off-PC', 'mark' => false, 'shutdown' => true]);
    // neither → status 'on' → "فعال" badge
    seedHardware($nCode, ['pc_name' => 'Active-PC', 'mark' => false, 'shutdown' => false]);

    $html = Livewire::test('hardware.index')->html();
    $this->assertStringContainsString('علامت', $html);
    $this->assertStringContainsString('خاموش', $html);
    $this->assertStringContainsString('فعال', $html);
});

it('applies warning highlight classes to marked rows', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    seedHardware($nCode, ['pc_name' => 'Marked-Highlight', 'mark' => true, 'shutdown' => false]);
    seedHardware($nCode, ['pc_name' => 'Normal-Row', 'mark' => false, 'shutdown' => false]);

    $html = Livewire::test('hardware.index')->html();
    // Table partial adds border-r-4 border-r-warning bg-warning/10 for mark rows
    $this->assertStringContainsString('border-r-warning', $html);
    $this->assertStringContainsString('bg-warning', $html);
});

// ==================== Bulk Selection ====================

it('renders bulk selection checkboxes', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    $hw1 = seedHardware($nCode, ['pc_name' => 'Select-PC-1']);
    $hw2 = seedHardware($nCode, ['pc_name' => 'Select-PC-2']);

    $html = Livewire::test('hardware.index')->html();
    $this->assertStringContainsString('wire:model.live="selected"', $html);
    $this->assertStringContainsString('value="'.$hw1->id.'"', $html);
    $this->assertStringContainsString('value="'.$hw2->id.'"', $html);
});

// ==================== Pagination ====================

it('paginates with configurable perPage values', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    // Create 25 records — exceeds default perPage (20)
    foreach (range(1, 25) as $i) {
        seedHardware($nCode, ['pc_name' => "Pag-PC-{$i}"]);
    }

    // Default perPage is 20 — page 1 shows 20 items
    $component = Livewire::test('hardware.index');
    $component->assertSee('Pag-PC-1')
        ->assertSee('Pag-PC-20');

    // Verify perPage property is settable and component re-renders
    $component->set('perPage', 10);
    $this->assertEquals(10, $component->get('perPage'));
    $component->assertSee('Pag-PC-1');
});

// ==================== Sort ====================

it('accepts sortBy property changes for column ordering', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    seedHardware($nCode, ['pc_name' => 'Zebra-PC']);
    seedHardware($nCode, ['pc_name' => 'Alpha-PC']);

    $component = Livewire::test('hardware.index');
    // Verify default sort (id desc) works — both visible
    $component->assertSee('Zebra-PC')
        ->assertSee('Alpha-PC');

    // Change sort to pc_name ascending
    $component->set('sortBy', ['column' => 'pc_name', 'direction' => 'asc']);
    // Both still visible after sort
    $component->assertSee('Alpha-PC')
        ->assertSee('Zebra-PC');
});

// ==================== Edit Modal ====================

it('opens edit modal when editing a hardware record', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    $hw = seedHardware($nCode, ['pc_name' => 'Edit-PC', 'type' => 'pc']);

    Livewire::test('hardware.index')
        ->call('editHardware', $hw->id)
        ->assertSet('showEditModal', true)
        ->assertSet('editingId', $hw->id)
        ->assertSet('pc_name', 'Edit-PC');
});

// ==================== History Modal ====================

it('loadHistory opens modal and populates history', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    $hw = seedHardware($nCode, ['pc_name' => 'History-PC']);
    // The observer auto-creates a 'created' audit entry

    $component = Livewire::test('hardware.index');
    $component->call('loadHistory', $hw->id)
        ->assertSet('showHistoryModal', true)
        ->assertSet('historyHardwareId', $hw->id);

    $history = $component->get('history');
    $this->assertNotEmpty($history);
    $this->assertEquals('created', $history[0]['action']);
});

// ==================== Delete ====================

it('deletes a hardware record and logs the deletion', function () {
    [$user, $unit, $nCode] = makeHardwareUser();
    $this->actingAs($user);

    $hw = seedHardware($nCode, ['pc_name' => 'Delete-PC']);

    Livewire::test('hardware.index')
        ->call('delete', $hw->id);

    $this->assertDatabaseMissing('hardwares', ['id' => $hw->id]);
    $this->assertDatabaseHas('hardware_audits', [
        'hardware_id' => $hw->id,
        'action' => 'deleted',
    ]);
});
