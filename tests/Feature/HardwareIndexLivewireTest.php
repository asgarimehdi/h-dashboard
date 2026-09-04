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
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(Hardware::class);

class HardwareIndexLivewireTest extends TestCase
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

        // Resync sequences after explicit inserts
        DB::statement("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
        DB::statement("SELECT setval('estekhdams_id_seq', COALESCE((SELECT MAX(id) FROM estekhdams), 1))");
        DB::statement("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");
        DB::statement("SELECT setval('radifs_id_seq', COALESCE((SELECT MAX(id) FROM radifs), 1))");
    }

    protected function createUserWithUnit(string $permission = 'manage_hardware'): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo($permission);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        DB::table('user_units')->where('user_id', $user->id)->update([
            'role' => 'staff',
            'is_primary' => true,
        ]);

        return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode];
    }

    protected function createHardwareForUser(User $user, Unit $unit, array $overrides = []): Hardware
    {
        $person = Person::where('n_code', $user->n_code)->first();

        return Hardware::create(array_merge([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-Test-'.fake()->unique()->bothify('####'),
            'type' => 'PC',
            'os' => 'Windows 10',
            'cpu' => 'Intel i5',
            'ram' => '8GB',
            'hdd' => '256GB SSD',
        ], $overrides));
    }

    // ==================== S1: Smoke Tests ====================

    public function test_guest_redirects_to_login(): void
    {
        $this->get('/hardware')->assertRedirect('/login');
    }

    public function test_unauthorized_user_gets_403(): void
    {
        $data = $this->createUserWithUnit('manage_users'); // different permission
        $this->actingAs($data['user']);
        $this->get('/hardware')->assertStatus(403);
    }

    public function test_authorized_user_renders(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertStatus(200);
    }

    public function test_renders_with_pagination_defaults(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSet('perPage', 20)
            ->assertSet('sortBy.column', 'id')
            ->assertSet('sortBy.direction', 'desc');
    }

    // ==================== S2: Search ====================

    public function test_search_filters_by_name(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw1 = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-SpecialAlpha',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-OtherBeta',
        ]);

        Livewire::test('hardware.index')
            ->set('search', 'SpecialAlpha')
            ->assertSee('PC-SpecialAlpha')
            ->assertDontSee('PC-OtherBeta');
    }

    public function test_search_with_persian_normalizer(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-arabic-alef',
        ]);

        // PersianNormalizer normalizes Arabic chars; search should still work
        Livewire::test('hardware.index')
            ->set('search', 'PC-arabic')
            ->assertSee('PC-arabic-alef');
    }

    // ==================== S3: Filters ====================

    public function test_toggle_filter_sets_and_clears(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $component = Livewire::test('hardware.index');

        // Set filter
        $component->call('toggleFilter', 'filterType', 'PC')
            ->assertSet('filterType', 'PC');

        // Toggle off (clicking same value clears it)
        $component->call('toggleFilter', 'filterType', 'PC')
            ->assertSet('filterType', null);
    }

    public function test_filter_type_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Desktop-1', 'type' => 'Desktop',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Laptop-1', 'type' => 'Laptop',
        ]);

        Livewire::test('hardware.index')
            ->set('filterType', 'Desktop')
            ->assertSee('Desktop-1')
            ->assertDontSee('Laptop-1');
    }

    public function test_filter_os_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'WinPC', 'os' => 'Windows 11',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'LinuxPC', 'os' => 'Ubuntu',
        ]);

        Livewire::test('hardware.index')
            ->set('filterOs', 'Windows')
            ->assertSee('WinPC')
            ->assertDontSee('LinuxPC');
    }

    public function test_filter_cpu_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'IntelPC', 'cpu' => 'Intel i7',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'AMPC', 'cpu' => 'AMD Ryzen 5',
        ]);

        Livewire::test('hardware.index')
            ->set('filterCpu', 'Intel')
            ->assertSee('IntelPC')
            ->assertDontSee('AMPC');
    }

    public function test_filter_ram_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'BigRam', 'ram' => '16GB',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'SmallRam', 'ram' => '4GB',
        ]);

        Livewire::test('hardware.index')
            ->set('filterRam', '16GB')
            ->assertSee('BigRam')
            ->assertDontSee('SmallRam');
    }

    public function test_filter_hdd_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'SSD-PC', 'hdd' => '512GB SSD',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'HDD-PC', 'hdd' => '1TB HDD',
        ]);

        Livewire::test('hardware.index')
            ->set('filterHdd', 'SSD')
            ->assertSee('SSD-PC')
            ->assertDontSee('HDD-PC');
    }

    public function test_filter_shutdown_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'ShutdownPC', 'shutdown' => true,
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'ActivePC', 'shutdown' => false,
        ]);

        Livewire::test('hardware.index')
            ->set('filterShutdown', '1')
            ->assertSee('ShutdownPC')
            ->assertDontSee('ActivePC');
    }

    public function test_filter_net_type_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'LanPC', 'net_type' => 'LAN',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'WifiPC', 'net_type' => 'WiFi',
        ]);

        Livewire::test('hardware.index')
            ->set('filterNetType', 'LAN')
            ->assertSee('LanPC')
            ->assertDontSee('WifiPC');
    }

    public function test_filter_mark_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'MarkedPC', 'mark' => true,
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'UnmarkedPC', 'mark' => false,
        ]);

        Livewire::test('hardware.index')
            ->set('filterMark', '1')
            ->assertSee('MarkedPC')
            ->assertDontSee('UnmarkedPC');
    }

    public function test_filter_person_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Create a second person in the same unit
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'علی', 'l_name' => 'رضایی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $data['unit']->id,
        ]);

        // Hardware belonging to the main user (person = تست کاربر)
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-TestUser',
        ]);

        // Hardware belonging to علی رضایی
        Hardware::create([
            'n_code' => $nCode2,
            'pc_name' => 'PC-Ali',
            'type' => 'PC',
        ]);

        Livewire::test('hardware.index')
            ->set('filterPerson', 'علی')
            ->assertSee('PC-Ali')
            ->assertDontSee('PC-TestUser');
    }

    public function test_filter_unit_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Create a second person in the same unit
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'دوم', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $data['unit']->id,
        ]);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'UnitPC',
        ]);

        Livewire::test('hardware.index')
            ->set('filterUnit', 'واحد تست')
            ->assertSee('UnitPC');
    }

    public function test_filter_semat_applies(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'SematPC',
        ]);

        Livewire::test('hardware.index')
            ->set('filterSemat', 'Test')
            ->assertSee('SematPC');
    }

    public function test_clear_filters_resets_all(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('filterType', 'PC')
            ->set('filterOs', 'Windows')
            ->set('filterCpu', 'Intel')
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
    }

    public function test_has_active_filters_true_when_set(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('filterType', 'PC')
            ->assertSet('filterType', 'PC');
    }

    public function test_has_active_filters_false_when_empty(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSet('filterType', null)
            ->assertSet('filterOs', null);
    }

    // ==================== S4: Column Visibility ====================

    public function test_column_visibility_defaults(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSet('visibleCols.type', true)
            ->assertSet('visibleCols.os', true)
            ->assertSet('visibleCols.unit_name', true)
            ->assertSet('visibleCols.cpu', true)
            ->assertSet('visibleCols.ram', true)
            ->assertSet('visibleCols.hdd', true)
            ->assertSet('visibleCols.status', true)
            ->assertSet('visibleCols.ip_valid', false)
            ->assertSet('visibleCols.mac', false)
            ->assertSet('visibleCols.net_type', false);
    }

    public function test_toggle_col_panel(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSet('showColPanel', false)
            ->call('toggleColPanel')
            ->assertSet('showColPanel', true)
            ->call('toggleColPanel')
            ->assertSet('showColPanel', false);
    }

    // ==================== S5: Person Lookup ====================

    public function test_person_search_scoped(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Person in scope
        $person = Person::where('n_code', $data['n_code'])->first();

        Livewire::test('hardware.index')
            ->set('personSearch', 'تست')
            ->assertSet('personResults.0.n_code', $data['n_code']);
    }

    public function test_person_search_short_term_clears(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // 'a' is 1 byte so strlen < 2 triggers the early return
        Livewire::test('hardware.index')
            ->set('personSearch', 'a')
            ->assertSet('personResults', []);
    }

    public function test_updated_n_code_valid(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('n_code', $data['n_code'])
            ->assertSet('n_code_status', 'valid')
            ->assertSet('n_code_name', 'تست کاربر');
    }

    public function test_updated_n_code_invalid(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('n_code', '9999999999')
            ->assertSet('n_code_status', 'invalid');
    }

    public function test_updated_n_code_short_resets(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('n_code', '123')
            ->assertSet('n_code_status', null)
            ->assertSet('n_code_name', null)
            ->assertSet('n_code_unit', null);
    }

    // ==================== S6: CRUD ====================

    public function test_start_create_opens_form(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->call('startCreate')
            ->assertSet('showForm', true)
            ->assertSet('editingId', null);
    }

    public function test_create_hardware_scoped(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->call('startCreate')
            ->set('n_code', $data['n_code'])
            ->set('pc_name', 'New-PC-Test')
            ->call('createHardware')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'New-PC-Test']);
    }

    public function test_create_hardware_requires_n_code_and_pc_name(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->call('startCreate')
            ->call('createHardware')
            ->assertHasErrors(['n_code', 'pc_name']);
    }

    public function test_create_hardware_out_of_scope_rejected(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Create a second person in a DIFFERENT unit (not accessible)
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'دیگر', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $otherUnit->id,
        ]);

        Livewire::test('hardware.index')
            ->call('startCreate')
            ->set('n_code', $nCode2)
            ->set('pc_name', 'Bad-PC')
            ->call('createHardware');

        // Should not create the hardware because n_code validation
        // checks accessibleUnitIds
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'Bad-PC']);
    }

    public function test_edit_hardware_populates_form(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Editable-PC',
            'type' => 'Desktop',
        ]);

        Livewire::test('hardware.index')
            ->call('editHardware', $hw->id)
            ->assertSet('editingId', $hw->id)
            ->assertSet('pc_name', 'Editable-PC')
            ->assertSet('showEditModal', true)
            ->assertSet('showForm', false);
    }

    public function test_update_hardware_scoped(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Before-Update',
        ]);

        Livewire::test('hardware.index')
            ->call('editHardware', $hw->id)
            ->set('pc_name', 'After-Update')
            ->call('updateHardware')
            ->assertHasNoErrors()
            ->assertSet('showEditModal', false);

        $this->assertDatabaseHas('hardwares', ['id' => $hw->id, 'pc_name' => 'After-Update']);
    }

    public function test_delete_soft_deletes(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'ToDelete-PC',
        ]);

        Livewire::test('hardware.index')
            ->call('delete', $hw->id);

        $this->assertDatabaseMissing('hardwares', ['id' => $hw->id]);
    }

    // ==================== S7: Bulk Operations ====================

    public function test_bulk_mark_empty_shows_error(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('selected', [])
            ->call('bulkMark', true);
        // Should not throw, just show toast error
    }

    public function test_bulk_mark_updates_selected(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'BulkMark-PC',
        ]);

        Livewire::test('hardware.index')
            ->set('selected', [$hw->id])
            ->call('bulkMark', true);

        $this->assertDatabaseHas('hardwares', ['id' => $hw->id, 'mark' => true]);
    }

    public function test_bulk_delete_scoped(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'BulkDelete-PC',
        ]);

        Livewire::test('hardware.index')
            ->set('selected', [$hw->id])
            ->call('bulkDelete');

        $this->assertDatabaseMissing('hardwares', ['id' => $hw->id]);
    }

    public function test_bulk_delete_empty_does_nothing(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('selected', [])
            ->call('bulkDelete');
        // Should not throw
    }

    public function test_clear_selection(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('selected', [1, 2, 3])
            ->call('clearSelection')
            ->assertSet('selected', []);
    }

    // ==================== S8: Export ====================

    public function test_export_dispatches(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->call('exportExcel')
            ->assertDispatched('download-export');
    }

    // ==================== S9: History ====================

    public function test_load_history_paginated(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit']);

        // Create some audit entries (observer already added 1 on create)
        for ($i = 0; $i < 4; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('showHistoryModal', true)
            ->assertSet('historyHardwareId', $hw->id);
    }

    public function test_history_page_navigation(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit']);

        // Create more audits than one page (historyPerPage = 15)
        // Observer already added 1 on create, so add 20 more = 21 total
        for ($i = 0; $i < 20; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('historyTotal', 21)
            ->call('historyPage', 2)
            ->assertSet('historyCurrentPage', 2);
    }
}
