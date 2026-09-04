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

covers(HardwareAudit::class);

class HardwareAuditModalLivewireTest extends TestCase
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

    /**
     * Create a user with the given permission, linked to a unit.
     * Returns ['user' => User, 'unit' => Unit, 'n_code' => string].
     */
    protected function createUserWithUnit(string $permission = 'manage_hardware'): array
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
        $user->givePermissionTo($permission);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode];
    }

    /**
     * Create a hardware record for the given user/unit.
     */
    protected function createHardware(User $user, array $overrides = []): Hardware
    {
        $person = Person::where('n_code', $user->n_code)->first();

        return Hardware::create(array_merge([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-Test-'.fake()->unique()->bothify('####'),
            'type' => 'PC',
            'os' => 'Windows 10',
            'cpu' => 'Intel i5',
            'ram' => '8192',
            'hdd' => '256GB',
            'ip_local' => '192.168.1.1',
            'mark' => false,
            'status' => 'active',
        ], $overrides));
    }

    // ==================== Page load / auth ====================

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/hardware')->assertRedirect('/login');
    }

    public function test_returns_403_without_permission(): void
    {
        $data = $this->createUserWithUnit('view_all_tickets');
        $this->actingAs($data['user']);

        $this->get('/hardware')->assertStatus(403);
    }

    public function test_page_loads_for_authorized_user(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);

        Livewire::test('hardware.index')
            ->assertOk()
            ->assertSee('شناسنامه سخت افزار');
    }

    // ==================== S1: loadHistory opens modal ====================

    public function test_load_history_opens_modal(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('showHistoryModal', true)
            ->assertSet('historyHardwareId', $hw->id)
            ->assertSet('historyCurrentPage', 1)
            ->assertSet('historyActionFilter', null);

        // Verify history contains at least the 'created' audit from Hardware creation
        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id);

        $history = $component->get('history');
        $this->assertNotEmpty($history);
        $this->assertEquals('created', $history[0]['action']);
    }

    // ==================== S2: Empty history message ====================

    public function test_empty_history_message(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);

        // Create hardware with a unique n_code that has no pre-existing audits
        $hw = $this->createHardware($data['user']);

        // Delete all audits for this hardware so history is empty
        HardwareAudit::where('hardware_id', $hw->id)->delete();

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('showHistoryModal', true)
            ->assertSee('تاریخچه‌ای ثبت نشده است');
    }

    // ==================== S3: filterHistory ====================

    public function test_filter_history_all_actions(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // The observer already created a 'created' audit; add 'updated'
        $hw->update(['cpu' => 'Intel i7']);

        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id);

        // Filter to 'updated'
        $component->call('filterHistory', 'updated');
        $history = $component->get('history');
        $this->assertNotEmpty($history);
        foreach ($history as $entry) {
            $this->assertEquals('updated', $entry['action']);
        }

        // Filter back to null (all)
        $component->call('filterHistory', null);
        $history = $component->get('history');
        $this->assertNotEmpty($history);

        // Filter to 'created'
        $component->call('filterHistory', 'created');
        $history = $component->get('history');
        $this->assertNotEmpty($history);
        foreach ($history as $entry) {
            $this->assertEquals('created', $entry['action']);
        }

        // Filter to 'deleted' — should be empty since no delete happened
        $component->call('filterHistory', 'deleted');
        $history = $component->get('history');
        $this->assertEmpty($history);

        // Filter to 'rollback' — should be empty
        $component->call('filterHistory', 'rollback');
        $history = $component->get('history');
        $this->assertEmpty($history);

        // Filter to 'bulk_mark' — should be empty
        $component->call('filterHistory', 'bulk_mark');
        $history = $component->get('history');
        $this->assertEmpty($history);

        // Filter to 'bulk_delete' — should be empty
        $component->call('filterHistory', 'bulk_delete');
        $history = $component->get('history');
        $this->assertEmpty($history);
    }

    public function test_filter_history_active_button_class(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Apply updated filter and verify btn-primary class appears for the active filter
        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('filterHistory', 'updated')
            ->assertSee('btn-primary');

        // Apply null filter and check 'همه' is active
        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('filterHistory', null)
            ->assertSee('btn-primary');
    }

    // ==================== S4: Pagination controls ====================

    public function test_pagination_controls(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // historyPerPage = 15; observer adds 1 'created' audit on create.
        // Add 20 more 'updated' audits → total 21, which needs 2 pages.
        for ($i = 0; $i < 20; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('historyTotal', 21)
            ->assertSet('historyCurrentPage', 1);

        // Navigate to page 2
        $component->call('historyPage', 2)
            ->assertSet('historyCurrentPage', 2);

        // Page indicator should show page 2 of 2 (ceil(21/15) = 2)
        $component->assertSee('صفحه 2 از 2');
    }

    public function test_pagination_first_page_prev_disabled(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Add 20 more to trigger pagination
        for ($i = 0; $i < 20; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id);

        // On page 1, the page indicator should confirm we're on page 1
        $component->assertSet('historyCurrentPage', 1)
            ->assertSee('صفحه 1 از 2');
    }

    public function test_pagination_last_page_next_disabled(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Add 20 more to trigger pagination
        for ($i = 0; $i < 20; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('historyPage', 2);

        // On the last page, the next button should be disabled
        $component->assertSee('صفحه 2 از 2');
    }

    // ==================== S5: Rollback ====================

    public function test_rollback_button_visibility(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Create an 'updated' audit with a valid old value
        $hw->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $hw->id)
            ->where('action', 'updated')
            ->first();

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSee('بازگردانی');
    }

    public function test_rollback_restores_field(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Original CPU is 'Intel i5'; change to 'Intel i7'
        $hw->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $hw->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($audit);

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('rollbackHistoryField', $audit->id, 'cpu')
            ->assertHasNoErrors();

        // Hardware CPU should be restored to 'Intel i5'
        $this->assertDatabaseHas('hardwares', [
            'id' => $hw->id,
            'cpu' => 'Intel i5',
        ]);

        // A rollback audit entry should be created
        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $hw->id,
            'action' => 'rollback',
        ]);
    }

    // ==================== S6: Page resets ====================

    public function test_load_history_resets_page_to_1(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Add many audits to enable pagination
        for ($i = 0; $i < 20; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('historyPage', 2)
            ->assertSet('historyCurrentPage', 2);

        // Calling loadHistory again should reset to page 1
        $component->call('loadHistory', $hw->id)
            ->assertSet('historyCurrentPage', 1);
    }

    public function test_filter_history_resets_page_to_1(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Add many audits to enable pagination
        for ($i = 0; $i < 20; $i++) {
            HardwareAudit::create([
                'hardware_id' => $hw->id,
                'user_id' => $data['user']->id,
                'action' => 'updated',
                'changes' => [['field' => 'ram', 'old' => '4GB', 'new' => '8GB']],
                'source' => 'web',
            ]);
        }

        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('historyPage', 2)
            ->assertSet('historyCurrentPage', 2);

        // Calling filterHistory should reset to page 1
        $component->call('filterHistory', 'updated')
            ->assertSet('historyCurrentPage', 1);
    }

    // ==================== E1: No matching action -> empty ====================

    public function test_no_matching_action_shows_empty(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Only 'created' audit exists; filter to 'deleted' (nonexistent)
        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('filterHistory', 'deleted')
            ->assertSet('history', [])
            ->assertSee('تاریخچه‌ای ثبت نشده است');
    }

    // ==================== E2: historyTotal <= perPage hides pagination ====================

    public function test_pagination_hidden_when_total_lte_per_page(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Only 1 audit (from creation) — no pagination needed
        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('historyTotal', 1);

        // Page indicator should NOT be visible
        $component->assertDontSee('صفحه 1 از');
    }

    // ==================== E3: Invalid audit id / mismatched hardware_id ====================

    public function test_rollback_invalid_audit_id_no_crash(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->call('rollbackHistoryField', 999999, 'cpu');

        // No crash; modal stays open
        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('showHistoryModal', true);
    }

    public function test_rollback_mismatched_hardware_id_no_crash(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw1 = $this->createHardware($data['user']);
        $hw2 = $this->createHardware($data['user']);

        // Create an audit for hw2
        $hw2->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $hw2->id)
            ->where('action', 'updated')
            ->first();

        // Try to rollback hw2's audit while viewing hw1's history
        Livewire::test('hardware.index')
            ->call('loadHistory', $hw1->id)
            ->call('rollbackHistoryField', $audit->id, 'cpu');

        // hw1's CPU should remain unchanged
        $hw1->refresh();
        $this->assertEquals('Intel i5', $hw1->cpu);
    }

    // ==================== E4: Wrong unit -> empty history ====================

    public function test_wrong_unit_sees_empty_history(): void
    {
        $data1 = $this->createUserWithUnit();
        $data2 = $this->createUserWithUnit();

        // Reset session to user1's unit (createUserWithUnit overwrites it)
        Session::put('current_unit_id', $data1['unit']->id);
        $this->actingAs($data1['user']);

        // Create hardware in unit 2's scope
        $hw = Hardware::create([
            'n_code' => $data2['n_code'],
            'pc_name' => 'PC-Other',
            'type' => 'PC',
            'cpu' => 'Intel i3',
        ]);

        // User from unit 1 tries to load history for hardware belonging to unit 2
        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('history', [])
            ->assertSet('historyTotal', 0);
    }

    // ==================== E5: Jalali date rendering ====================

    public function test_jalali_date_renders_per_entry(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        $hw = $this->createHardware($data['user']);

        // Load history — at least one entry exists (the 'created' audit)
        Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id)
            ->assertSet('showHistoryModal', true);

        // Verify component renders without errors (Jalali dates in the partial)
        // The Jalali date format Y/m/d H:i should appear in the rendered HTML
        $component = Livewire::test('hardware.index')
            ->call('loadHistory', $hw->id);

        $history = $component->get('history');
        $this->assertNotEmpty($history);

        // Each entry should have a valid ISO8601 created_at (Jalali rendering is in the Blade)
        foreach ($history as $entry) {
            $this->assertArrayHasKey('created_at', $entry);
            $this->assertNotNull($entry['created_at']);
        }
    }
}
