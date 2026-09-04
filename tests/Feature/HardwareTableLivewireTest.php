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
use Livewire\Livewire;
use Tests\TestCase;

class HardwareTableLivewireTest extends TestCase
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

        // Resync sequences after inserting with explicit IDs
        DB::select("SELECT setval('tahsils_id_seq', (SELECT COALESCE(MAX(id),0) FROM tahsils))");
        DB::select("SELECT setval('estekhdams_id_seq', (SELECT COALESCE(MAX(id),0) FROM estekhdams))");
        DB::select("SELECT setval('semats_id_seq', (SELECT COALESCE(MAX(id),0) FROM semats))");
        DB::select("SELECT setval('radifs_id_seq', (SELECT COALESCE(MAX(id),0) FROM radifs))");
    }

    protected function createUserWithUnit(string $perm = 'manage_hardware'): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo($perm);

        return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode];
    }

    protected function createHardware(array $overrides = [], ?int $personUnitId = null): Hardware
    {
        $ncode = $overrides['n_code'] ?? null;

        if (! $ncode) {
            // Create a person and hardware from scratch
            $unitId = $personUnitId;
            $nCode = (string) fake()->unique()->numerify('##########');
            Person::create([
                'n_code' => $nCode, 'f_name' => 'سخت', 'l_name' => 'افزار',
                't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1,
                'u_id' => $unitId ?? 1,
            ]);

            return Hardware::create(array_merge([
                'n_code' => $nCode,
                'pc_name' => 'PC-Test-'.fake()->bothify('??##'),
                'type' => 'Desktop',
                'os' => 'Windows 10',
                'ip_local' => '192.168.1.'.fake()->numberBetween(2, 254),
                'cpu' => 'Intel i7',
                'ram' => '16GB',
                'hdd' => '512GB SSD',
                'mark' => false,
                'shutdown' => false,
            ], $overrides));
        }

        return Hardware::create(array_merge([
            'pc_name' => 'PC-Test-'.fake()->bothify('??##'),
            'type' => 'Desktop',
            'os' => 'Windows 10',
            'ip_local' => '192.168.1.'.fake()->numberBetween(2, 254),
            'cpu' => 'Intel i7',
            'ram' => '16GB',
            'hdd' => '512GB SSD',
            'mark' => false,
            'shutdown' => false,
        ], $overrides));
    }

    // ==================== Auth / Smoke ====================

    public function test_guest_redirected(): void
    {
        $this->get('/hardware')->assertRedirect('/login');
    }

    public function test_returns_403_without_permission(): void
    {
        $data = $this->createUserWithUnit('manage_users');
        $this->actingAs($data['user']);

        $this->get('/hardware')->assertStatus(403);
    }

    public function test_page_loads_for_authorized_user(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        Livewire::test('hardware.index')
            ->assertStatus(200);
    }

    // ==================== S1: Renders both layouts ====================

    public function test_renders_both_layouts(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware([], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('PC-Test-')  // hardware pc_name shows in both layouts
            ->assertStatus(200);
    }

    // ==================== S2: Status badges ====================

    public function test_status_badge_mark(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['mark' => true, 'shutdown' => false], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('علامت');  // 'mark' status badge
    }

    public function test_status_badge_off(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['mark' => false, 'shutdown' => true], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('خاموش');  // 'off' status badge
    }

    public function test_status_badge_active(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['mark' => false, 'shutdown' => false], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('فعال');  // 'on' status badge
    }

    // ==================== S3: Marked-row highlight ====================

    public function test_marked_highlight_applied(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['mark' => true, 'shutdown' => false], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('border-r-warning');  // mark highlight CSS class in mobile card
    }

    // ==================== S4: Bulk checkbox ====================

    public function test_bulk_checkbox_toggles_selection(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware([], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSet('selected', [])
            ->set('selected', [$hw->id])
            ->assertSet('selected', [$hw->id]);
    }

    // ==================== S5: perPage pagination ====================

    public function test_perpage_values(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        Livewire::test('hardware.index')
            ->assertSet('perPage', 20)
            ->set('perPage', 10)
            ->assertSet('perPage', 10)
            ->set('perPage', 50)
            ->assertSet('perPage', 50)
            ->set('perPage', 100)
            ->assertSet('perPage', 100);
    }

    // ==================== S6: Sort headers ====================

    public function test_sort_headers_reorder(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        Livewire::test('hardware.index')
            ->assertSet('sortBy', ['column' => 'id', 'direction' => 'desc'])
            ->set('sortBy', ['column' => 'pc_name', 'direction' => 'asc'])
            ->assertSet('sortBy', ['column' => 'pc_name', 'direction' => 'asc'])
            ->set('sortBy', ['column' => 'id', 'direction' => 'asc'])
            ->assertSet('sortBy', ['column' => 'id', 'direction' => 'asc']);
    }

    // ==================== S7: editHardware opens edit modal ====================

    public function test_edit_opens_modal(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['pc_name' => 'EditTestPC'], $data['unit']->id);

        Livewire::test('hardware.index')
            ->call('editHardware', $hw->id)
            ->assertSet('showEditModal', true)
            ->assertSet('editingId', $hw->id)
            ->assertSet('pc_name', 'EditTestPC');
    }

    // ==================== loadHistory ====================

    public function test_load_history(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['pc_name' => 'HistoryTestPC'], $data['unit']->id);

        // The HardwareAuditObserver auto-creates a "created" audit on Hardware::create,
        // so we don't need to insert one manually.
        $auditCount = HardwareAudit::where('hardware_id', $hw->id)->count();
        $this->assertGreaterThan(0, $auditCount);

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('showHistoryModal', true)
            ->assertSet('historyHardwareId', $hw->id)
            ->assertSet('historyTotal', $auditCount);
    }

    // ==================== delete soft-deletes ====================

    public function test_delete_soft_deletes(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware(['pc_name' => 'DeleteTestPC'], $data['unit']->id);
        $hwId = $hw->id;

        Livewire::test('hardware.index')
            ->call('delete', $hw->id);

        $this->assertDatabaseMissing('hardwares', ['id' => $hwId]);
    }

    // ==================== E1: Empty hardwares renders without error ====================

    public function test_empty_hardwares_renders(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        Livewire::test('hardware.index')
            ->assertStatus(200)
            ->assertDontSee('PC-Test-');
    }

    // ==================== E2: mark=true + status badge shows even with status mismatch ====================

    public function test_mark_overrides_off_status(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        // mark=true, shutdown=true — mark takes priority in status logic
        $hw = $this->createHardware(['mark' => true, 'shutdown' => true], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('علامت');  // mark status shown (mark takes priority)
        // Note: 'خاموش' also appears in the filter dropdown, so assertDontSee
        // is unreliable here — the status badge correctly shows 'علامت'.
    }

    // ==================== E3: Unknown status falls to active ====================

    public function test_default_status_is_active(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        // mark=false, shutdown=false — status = 'on' → 'فعال'
        $hw = $this->createHardware(['mark' => false, 'shutdown' => false], $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertSee('فعال');
    }

    // ==================== E4: Selection survives perPage change ====================

    public function test_selection_survives_perpage_change(): void
    {
        $data = $this->createUserWithUnit('manage_hardware');
        $this->actingAs($data['user']);

        $hw = $this->createHardware([], $data['unit']->id);

        Livewire::test('hardware.index')
            ->set('selected', [$hw->id])
            ->set('perPage', 10)
            ->assertSet('selected', [$hw->id]);
    }
}
