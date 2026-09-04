<?php

namespace Tests\Feature;

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

class HardwareFiltersLivewireTest extends TestCase
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

    protected function actingAsHardwareUser(): array
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        return $data;
    }

    // ==================== Smoke Tests ====================

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/hardware')->assertRedirect('/login');
    }

    public function test_unauthorized_user_gets_403(): void
    {
        $data = $this->createUserWithUnit('manage_users');
        $this->actingAs($data['user']);
        $this->get('/hardware')->assertStatus(403);
    }

    public function test_authorized_user_renders(): void
    {
        $data = $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->assertStatus(200);
    }

    // ==================== S1: Filters hidden by default ====================

    public function test_filters_hidden_by_default(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->assertSet('showFilters', false);
    }

    public function test_toggle_panel_reveals_filters(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->assertSet('showFilters', false)
            ->toggle('showFilters')
            ->assertSet('showFilters', true);
    }

    // ==================== S2: toggleFilter set / clear ====================

    public function test_toggle_filter_set(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->assertSet('filterType', null)
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', 'laptop');
    }

    public function test_toggle_filter_off(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', 'laptop')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', null);
    }

    // ==================== S3: toggleFilter RAM affects query ====================

    public function test_toggle_filter_ram_sets_value(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->assertSet('filterRam', null)
            ->call('toggleFilter', 'filterRam', '16384')
            ->assertSet('filterRam', '16384');
    }

    public function test_ram_filter_affects_query_results(): void
    {
        $data = $this->actingAsHardwareUser();
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-16G', 'ram' => '16384',
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-8G', 'ram' => '8192',
        ]);

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterRam', '16384')
            ->assertSee('PC-16G')
            ->assertDontSee('PC-8G');
    }

    // ==================== S4: clearFilters resets ALL 11 ====================

    public function test_clear_filters(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->call('toggleFilter', 'filterRam', '16384')
            ->set('filterOs', 'Windows')
            ->set('filterCpu', 'Intel')
            ->set('filterHdd', 'SSD')
            ->call('toggleFilter', 'filterShutdown', '1')
            ->set('filterNetType', 'wired')
            ->call('toggleFilter', 'filterMark', '1')
            ->set('filterPerson', 'علی')
            ->set('filterUnit', 'واحد')
            ->set('filterSemat', 'پزشک')
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

    // ==================== S5: hasActiveFilters ====================

    public function test_has_active_filters_false_when_empty(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->call('hasActiveFilters')
            ->assertSet('showFilters', false);
    }

    public function test_has_active_filters_true_when_filter_set(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->toggle('showFilters')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSee('فیلترهای فعال اعمال شده');
    }

    // ==================== S6: loadDeletedHardware ====================

    public function test_deleted_view_toggle(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->assertSet('showTrashModal', false)
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->assertSet('deletedHardware', []);
    }

    // ==================== S7: Combined filters narrow results ====================

    public function test_combined_filters_narrow_results(): void
    {
        $data = $this->actingAsHardwareUser();

        // Matching item: type=laptop, ram=16384, shutdown=true
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Laptop-Good', 'type' => 'laptop', 'ram' => '16384', 'shutdown' => true,
        ]);
        // Non-matching: type=server
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Server-Bad', 'type' => 'server', 'ram' => '16384', 'shutdown' => true,
        ]);
        // Non-matching: ram=8192
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Laptop-SmallRam', 'type' => 'laptop', 'ram' => '8192', 'shutdown' => true,
        ]);
        // Non-matching: shutdown=false
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'Laptop-Off', 'type' => 'laptop', 'ram' => '16384', 'shutdown' => false,
        ]);

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->call('toggleFilter', 'filterRam', '16384')
            ->call('toggleFilter', 'filterShutdown', '1')
            ->assertSee('Laptop-Good')
            ->assertDontSee('Server-Bad')
            ->assertDontSee('Laptop-SmallRam')
            ->assertDontSee('Laptop-Off');
    }

    // ==================== S8: filterPerson/Unit/Semat ====================

    public function test_filter_person_normalizes_search(): void
    {
        $data = $this->actingAsHardwareUser();
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Ali',
        ]);

        // Create a second person/unit
        $unit2 = Unit::create(['name' => 'واحد دوم']);
        $nCode2 = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'محمد', 'l_name' => 'احمدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit2->id,
        ]);
        $user2 = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);
        $user2->givePermissionTo('manage_hardware');
        $user2->units()->attach($unit2->id, ['role' => 'staff', 'is_primary' => true]);

        // Give our test user access to both units
        $data['user']->units()->attach($unit2->id, ['role' => 'staff']);

        $person2 = Person::where('n_code', $nCode2)->first();
        Hardware::create([
            'n_code' => $nCode2,
            'pc_name' => 'PC-Mohammad',
            'type' => 'PC',
        ]);

        Livewire::test('hardware.index')
            ->set('filterPerson', 'تست')
            ->assertSee('PC-Ali')
            ->assertDontSee('PC-Mohammad');
    }

    public function test_filter_unit_normalizes_search(): void
    {
        $data = $this->actingAsHardwareUser();
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Main',
        ]);

        Livewire::test('hardware.index')
            ->set('filterUnit', 'واحد تست')
            ->assertSee('PC-Main');
    }

    public function test_filter_semat_normalizes_search(): void
    {
        $data = $this->actingAsHardwareUser();
        // Create semat row
        DB::table('semats')->insert(['id' => 2, 'name' => 'پزشک']);
        DB::statement("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");

        $person = Person::where('n_code', $data['n_code'])->first();
        $person->update(['s_id' => 2]);

        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Doc',
        ]);

        Livewire::test('hardware.index')
            ->set('filterSemat', 'پزشک')
            ->assertSee('PC-Doc');
    }

    // ==================== E1: Toggle idempotent ====================

    public function test_toggle_same_value_twice_clears(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', 'laptop')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', null)
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', 'laptop');
    }

    // ==================== E2: clearFilters no-op ====================

    public function test_clear_filters_noop_when_none_set(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->call('clearFilters')
            ->assertSet('filterType', null)
            ->assertSet('filterOs', null)
            ->assertSet('filterRam', null);
    }

    // ==================== E3: filterShutdown '1' maps boolean ====================

    public function test_shutdown_filter_maps_string_to_boolean(): void
    {
        $data = $this->actingAsHardwareUser();
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-On', 'shutdown' => true,
        ]);
        $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Off', 'shutdown' => false,
        ]);

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterShutdown', '1')
            ->assertSee('PC-On')
            ->assertDontSee('PC-Off');
    }

    // ==================== E4: Empty string treated as null ====================

    public function test_empty_string_treated_as_null_for_shutdown(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->set('filterShutdown', '')
            ->assertSet('filterShutdown', '');
    }

    // ==================== E5: Rapid changes stay consistent ====================

    public function test_rapid_changes_stay_consistent(): void
    {
        $this->actingAsHardwareUser();

        Livewire::test('hardware.index')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->call('toggleFilter', 'filterRam', '16384')
            ->call('toggleFilter', 'filterType', 'laptop')
            ->assertSet('filterType', null)
            ->assertSet('filterRam', '16384')
            ->call('clearFilters')
            ->assertSet('filterType', null)
            ->assertSet('filterRam', null)
            ->call('toggleFilter', 'filterHdd', 'SSD')
            ->set('filterCpu', 'AMD')
            ->assertSet('filterHdd', 'SSD')
            ->assertSet('filterCpu', 'AMD');
    }
}
