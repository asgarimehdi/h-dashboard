<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

function createUserWithUnit(bool $givePermission = true): array
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
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    if ($givePermission) {
        Permission::firstOrCreate(['name' => 'manage_hardware']);
        $user->givePermissionTo('manage_hardware');
    }

    return [$user, $unit];
}

// ─── 1. Guest redirect ─────────────────────────────────────────────

it('redirects guest to login', function () {
    $this->get('/hardware')->assertRedirect('/login');
});

// ─── 2. Unauthorized user gets 403 ─────────────────────────────────

it('returns 403 for user without manage_hardware permission', function () {
    [$user] = createUserWithUnit(givePermission: false);

    $this->actingAs($user);
    $this->get('/hardware')->assertForbidden();
});

// ─── 3. Authorized user can view ────────────────────────────────────

it('allows authorized user to view hardware page', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')->assertStatus(200);
});

// ─── 4. Filters hidden by default ──────────────────────────────────

it('hides filters panel by default', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->assertSet('showFilters', false)
        ->assertDontSeeLivewire('filterType');
});

// ─── 5. Toggle filter panel ────────────────────────────────────────

it('toggles the advanced filter panel open and closed', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->assertSet('showFilters', false)
        ->set('showFilters', true)
        ->assertSet('showFilters', true)
        ->set('showFilters', false)
        ->assertSet('showFilters', false);
});

// ─── 6. toggleFilter sets a value ──────────────────────────────────

it('sets a filter property via toggleFilter', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->call('toggleFilter', 'filterType', 'laptop')
        ->assertSet('filterType', 'laptop');
});

// ─── 7. toggleFilter clears when same value ────────────────────────

it('clears filter property when toggled with same value', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->call('toggleFilter', 'filterType', 'laptop')
        ->assertSet('filterType', 'laptop')
        ->call('toggleFilter', 'filterType', 'laptop')
        ->assertSet('filterType', null);
});

// ─── 8. clearFilters resets all 11 properties ──────────────────────

it('clears all 11 filter properties', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->call('toggleFilter', 'filterType', 'laptop')
        ->call('toggleFilter', 'filterOs', 'Windows 10')
        ->call('toggleFilter', 'filterCpu', 'Intel')
        ->call('toggleFilter', 'filterRam', '16384')
        ->call('toggleFilter', 'filterHdd', 'SSD')
        ->call('toggleFilter', 'filterShutdown', '1')
        ->call('toggleFilter', 'filterNetType', 'wired')
        ->call('toggleFilter', 'filterMark', '1')
        ->call('toggleFilter', 'filterPerson', 'test')
        ->call('toggleFilter', 'filterUnit', 'test')
        ->call('toggleFilter', 'filterSemat', 'test')
        ->call('clearFilters')
        ->assertSet('filterType', null)
        ->assertSet('filterOs', null)
        ->assertSet('filterCpu', null)
        ->assertSet('filterRam', null)
        ->assertSet('filterHdd', null)
        ->assertSet('filterShutdown', null)
        ->assertSet('filterNetType', null)
        ->assertSet('filterMark', null)
        ->assertSet('filterPerson', null)
        ->assertSet('filterUnit', null)
        ->assertSet('filterSemat', null);
});

// ─── 9. hasActiveFilters ───────────────────────────────────────────

it('hasActiveFilters shows indicator when any filter is set', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->set('showFilters', true)
        // No filters active yet — indicator text should not appear
        ->assertDontSee('فیلترهای فعال اعمال شده')
        // Set a filter — indicator text should appear
        ->call('toggleFilter', 'filterRam', '16384')
        ->assertSee('فیلترهای فعال اعمال شده')
        // Clear all — indicator should disappear
        ->call('clearFilters')
        ->assertDontSee('فیلترهای فعال اعمال شده');
});

// ─── 10. loadDeletedHardware opens trash modal ─────────────────────

it('loadDeletedHardware opens the trash modal', function () {
    [$user] = createUserWithUnit();

    Livewire::actingAs($user)->test('hardware.index')
        ->assertSet('showTrashModal', false)
        ->call('loadDeletedHardware')
        ->assertSet('showTrashModal', true);
});

// ─── 11. Combined filters narrow results ───────────────────────────

it('combines multiple filters to narrow hardware list', function () {
    [$user, $unit] = createUserWithUnit();

    Hardware::create([
        'n_code' => $user->n_code, 'pc_name' => 'LAPTOP-01',
        'type' => 'laptop', 'ram' => '16384', 'shutdown' => true,
    ]);
    Hardware::create([
        'n_code' => $user->n_code, 'pc_name' => 'SERVER-01',
        'type' => 'server', 'ram' => '32768', 'shutdown' => true,
    ]);
    Hardware::create([
        'n_code' => $user->n_code, 'pc_name' => 'LAPTOP-02',
        'type' => 'laptop', 'ram' => '8192', 'shutdown' => false,
    ]);

    Livewire::actingAs($user)->test('hardware.index')
        ->call('toggleFilter', 'filterType', 'laptop')
        ->call('toggleFilter', 'filterRam', '16384')
        ->call('toggleFilter', 'filterShutdown', '1')
        ->assertSee('LAPTOP-01')
        ->assertDontSee('SERVER-01')
        ->assertDontSee('LAPTOP-02');
});
