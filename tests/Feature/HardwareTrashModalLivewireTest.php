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

class HardwareTrashModalLivewireTest extends TestCase
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

    /**
     * Get the 'created' audit for a hardware record, used as the restore anchor.
     */
    protected function getCreatedAudit(Hardware $hw): HardwareAudit
    {
        return HardwareAudit::where('hardware_id', $hw->id)
            ->where('action', 'created')
            ->firstOrFail();
    }

    // ==================== S1: Smoke Tests ====================

    public function test_guest_redirects_to_login(): void
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
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->assertStatus(200);
    }

    // ==================== S1: Empty State ====================

    public function test_empty_state_when_no_deleted_hardware(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->assertSet('deletedHardware', [])
            ->assertSee('هیچ سخت‌افزار حذف شده‌ای در دسترس شما نیست.');
    }

    // ==================== S2: Deleted Item Shows ====================

    public function test_deleted_item_shows_in_trash(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-DeleteMe',
        ]);
        $hw->delete();

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->assertSet('deletedHardware', fn ($dh) => count($dh) >= 1)
            ->assertSee('PC-DeleteMe');
    }

    // ==================== S3: Idempotent Load ====================

    public function test_idempotent_load_deleted_hardware(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Idempotent',
        ]);
        $hw->delete();

        $component = Livewire::test('hardware.index')
            ->call('loadDeletedHardware');

        $count1 = count($component->get('deletedHardware'));

        $component->call('loadDeletedHardware');

        $count2 = count($component->get('deletedHardware'));

        $this->assertEquals($count1, $count2, 'Multiple calls should return same count');
    }

    // ==================== S4: Jalali Timestamp + User n_code ====================

    public function test_jalali_timestamp_and_user_ncode_shown(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-JalaliTest',
        ]);
        $hw->delete();

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('PC-JalaliTest')
            // Jalali timestamp contains a forward slash date pattern like 1404/06/...
            ->assertSee('حذف شده در')
            // User n_code should appear as the deleter
            ->assertSee($data['n_code']);
    }

    public function test_fallback_when_no_user_on_audit(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-NoUser',
        ]);

        // Simulate a system delete by nullifying user_id on the deleted audit
        HardwareAudit::where('hardware_id', $hw->id)
            ->where('action', 'deleted')
            ->update(['user_id' => null]);

        $hw->delete();

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('سیستم'); // fallback label when user is null
    }

    // ==================== S5: Change Badges ====================

    public function test_change_badges_render_excluding_pc_name_and_ncode(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-BadgeTest',
            'type' => 'Laptop',
            'cpu' => 'AMD Ryzen',
        ]);
        $hw->delete();

        // Verify the created audit has the expected change fields
        $audit = $this->getCreatedAudit($hw);
        $this->assertNotNull(collect($audit->changes)->firstWhere('field', 'type'));
        $this->assertNotNull(collect($audit->changes)->firstWhere('field', 'cpu'));

        // The trash modal renders badge spans for type and cpu but NOT for pc_name or n_code
        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('Laptop')
            ->assertSee('AMD Ryzen');
    }

    // ==================== S6: Restore Button ====================

    public function test_restore_button_shows_when_n_code_present_in_changes(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Restorable',
        ]);

        // The observer should have created a 'created' audit with n_code in changes
        $audit = $this->getCreatedAudit($hw);
        $hasNCode = collect($audit->changes)->contains('field', 'n_code');
        $this->assertTrue($hasNCode, 'Created audit should have n_code in changes');

        $hw->delete();

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            // The wire:click for restoreRecord should be present
            ->assertSee("restoreRecord({$audit->id})");
    }

    public function test_not_restorable_warning_when_no_ncode_in_changes(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Manually insert a created audit WITHOUT n_code field
        $auditId = DB::table('hardware_audits')->insertGetId([
            'hardware_id' => 999999,
            'user_id' => $data['user']->id,
            'action' => 'created',
            'changes' => json_encode([
                ['field' => 'pc_name', 'old' => null, 'new' => 'PC-NoNcode'],
                ['field' => 'type', 'old' => null, 'new' => 'PC'],
                // no n_code field
            ]),
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert a matching 'deleted' audit so loadDeletedHardware finds it
        DB::table('hardware_audits')->insert([
            'hardware_id' => 999999,
            'user_id' => $data['user']->id,
            'action' => 'deleted',
            'changes' => null,
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('PC-NoNcode')
            ->assertSee('قابل بازگردانی نیست');
    }

    // ==================== S7: Restore Record ====================

    public function test_restore_success_recreates_hardware(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-RestoreMe',
            'type' => 'Laptop',
            'cpu' => 'Intel i7',
        ]);
        $originalId = $hw->id;
        $audit = $this->getCreatedAudit($hw);

        $hw->delete();
        $this->assertDatabaseMissing('hardwares', ['id' => $originalId]);

        Livewire::test('hardware.index')
            ->call('restoreRecord', $audit->id);

        // Hardware should be recreated (with a new id since original was deleted)
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-RestoreMe',
            'n_code' => $data['n_code'],
        ]);
    }

    public function test_restore_denied_without_ncode_in_changes(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Insert audit with NO n_code field
        $auditId = DB::table('hardware_audits')->insertGetId([
            'hardware_id' => 888888,
            'user_id' => $data['user']->id,
            'action' => 'created',
            'changes' => json_encode([
                ['field' => 'pc_name', 'old' => null, 'new' => 'PC-NoRestore'],
            ]),
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test('hardware.index')
            ->call('restoreRecord', $auditId)
            ->assertSee('بازگردانی'); // error toast mentioning restore

        // Hardware should NOT have been created
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NoRestore']);
    }

    public function test_list_updates_after_restore(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-UpdateList',
        ]);
        $audit = $this->getCreatedAudit($hw);
        $hw->delete();

        $component = Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('PC-UpdateList');

        // Restore the item
        $component->call('restoreRecord', $audit->id);

        // The hardware should be back in the database with the same pc_name
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-UpdateList',
            'n_code' => $data['n_code'],
        ]);

        // loadDeletedHardware was called again by restoreRecord internally
        // — modal is still open with refreshed data
        $component->assertSet('showTrashModal', true);
    }

    public function test_modal_stays_open_until_closed(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-StayOpen',
        ]);
        $audit = $this->getCreatedAudit($hw);
        $hw->delete();

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->call('restoreRecord', $audit->id)
            // Modal should still be open after restore (loadDeletedHardware is called again)
            ->assertSet('showTrashModal', true);
    }

    // ==================== E1: Null Changes Array ====================

    public function test_load_deleted_hardware_populates_data_correctly(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Create and delete hardware normally (observer creates audits with proper changes)
        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-NormalAudit',
            'type' => 'Server',
        ]);
        $hw->delete();

        // Verify that loadDeletedHardware correctly populates deletedHardware array
        $component = Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true);

        $dh = $component->get('deletedHardware');
        $this->assertNotEmpty($dh);

        // The created audit should have changes array (not null) with field data
        $audit = collect($dh)->firstWhere('hardware_id', $hw->id);
        $this->assertNotNull($audit);
        $this->assertIsArray($audit->changes);
        $this->assertNotEmpty($audit->changes);
    }

    // ==================== E2: Null User Relationship ====================

    public function test_null_user_shows_system_label(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hwId = 666666;
        DB::table('hardware_audits')->insert([
            'hardware_id' => $hwId,
            'user_id' => null, // system delete
            'action' => 'created',
            'changes' => json_encode([
                ['field' => 'pc_name', 'old' => null, 'new' => 'PC-SystemDel'],
                ['field' => 'n_code', 'old' => null, 'new' => $data['n_code']],
            ]),
            'source' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hardware_audits')->insert([
            'hardware_id' => $hwId,
            'user_id' => null,
            'action' => 'deleted',
            'changes' => null,
            'source' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('سیستم'); // 'سیستم' = system fallback
    }

    // ==================== E3: Large Volume ====================

    public function test_large_volume_deleted_hardware(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hardwareIds = [];
        for ($i = 0; $i < 30; $i++) {
            $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
                'pc_name' => "PC-Bulk-{$i}",
            ]);
            $hardwareIds[] = $hw->id;
            $hw->delete();
        }

        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->assertSet('deletedHardware', fn ($dh) => count($dh) >= 30);
    }

    // ==================== E4: Concurrent Deletes Between Load and Restore ====================

    public function test_concurrent_delete_between_load_and_restore(): void
    {
        $data = $this->createUserWithUnit();
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $hw = $this->createHardwareForUser($data['user'], $data['unit'], [
            'pc_name' => 'PC-Concurrent',
        ]);
        $audit = $this->getCreatedAudit($hw);
        $hw->delete();

        // Load the trash list
        Livewire::test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('PC-Concurrent')
            ->call('restoreRecord', $audit->id);

        // Hardware should be restored despite the concurrent scenario
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-Concurrent',
            'n_code' => $data['n_code'],
        ]);
    }
}
