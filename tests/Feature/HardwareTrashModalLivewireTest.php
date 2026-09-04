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
    }

    protected function createUserWithUnit(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    /** Create a hardware record and delete it so 'created' + 'deleted' audits exist. */
    protected function createDeletedHardware(User $user, array $overrides = []): array
    {
        $nCode = $user->person->n_code;
        $hw = Hardware::create(array_merge([
            'n_code' => $nCode, 'pc_name' => 'TRASH_PC', 'type' => 'pc',
            'os' => '11 Windows', 'mac' => 'aa:bb:cc:dd:ee:ff', 'shutdown' => false, 'mark' => false,
        ], $overrides));

        $hwId = $hw->id;
        $hw->delete();

        $createdAudit = HardwareAudit::where('hardware_id', $hwId)
            ->where('action', 'created')
            ->firstOrFail();

        return [$hwId, $createdAudit];
    }

    // ── Auth / Access tests ──────────────────────────────────────────

    public function test_guest_request_redirected(): void
    {
        $this->get('/hardware')->assertRedirect('/login');
    }

    public function test_unauthorized_user_gets_403(): void
    {
        $user = $this->createUserWithUnit();
        // User has no manage_hardware permission
        $this->actingAs($user)->get('/hardware')->assertStatus(403);
    }

    public function test_authorized_user_can_view(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        $this->actingAs($user)->get('/hardware')->assertOk();
    }

    // ── Trash modal state tests ──────────────────────────────────────

    public function test_empty_state(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->assertSet('deletedHardware', [])
            ->assertSee('هیچ سخت‌افزار حذف شده‌ای');
    }

    public function test_deleted_item_shows(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        $this->createDeletedHardware($user, ['pc_name' => 'DELETED_PC']);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSet('showTrashModal', true)
            ->assertCount('deletedHardware', 1)
            ->assertSee('DELETED_PC');
    }

    public function test_idempotent_load(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        $this->createDeletedHardware($user);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertCount('deletedHardware', 1)
            ->call('loadDeletedHardware')
            ->assertCount('deletedHardware', 1)
            ->assertSet('showTrashModal', true);
    }

    public function test_jalali_timestamp(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        $this->createDeletedHardware($user);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertSee('حذف شده در');
    }

    // ── Restore tests ────────────────────────────────────────────────

    public function test_restore_success(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        [$hwId, $createdAudit] = $this->createDeletedHardware($user, ['pc_name' => 'RESTORE_PC']);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertCount('deletedHardware', 1)
            ->call('restoreRecord', $createdAudit->id)
            ->assertHasNoErrors();

        // Hardware was restored as a new record (restore creates a new row)
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'RESTORE_PC',
            'n_code' => $user->person->n_code,
        ]);

        // A rollback audit was logged for the restored hardware
        $restoredHw = Hardware::where('pc_name', 'RESTORE_PC')->first();
        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $restoredHw->id,
            'action' => 'rollback',
            'user_id' => $user->id,
        ]);
    }

    public function test_restore_denied_without_ncode(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        // Insert an audit directly — no n_code in changes
        $auditId = DB::table('hardware_audits')->insertGetId([
            'hardware_id' => 999999,
            'user_id' => $user->id,
            'action' => 'created',
            'changes' => json_encode([
                ['field' => 'pc_name', 'old' => '—', 'new' => 'NO_NCODE_PC'],
                ['field' => 'type', 'old' => '—', 'new' => 'pc'],
                // deliberately no n_code field
            ]),
            'source' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('restoreRecord', $auditId)
            ->assertHasNoErrors();

        // No hardware was created (restore was denied because n_code is missing)
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'NO_NCODE_PC']);
    }

    public function test_list_updates_after_restore(): void
    {
        $user = $this->createUserWithUnit();
        $user->givePermissionTo('manage_hardware');

        // Create TWO deleted hardware items
        $this->createDeletedHardware($user, ['pc_name' => 'STAY_DELETED']);
        [$hwId2, $createdAudit2] = $this->createDeletedHardware($user, ['pc_name' => 'WILL_RESTORE']);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->call('loadDeletedHardware')
            ->assertCount('deletedHardware', 2)
            ->call('restoreRecord', $createdAudit2->id)
            // After restore, loadDeletedHardware() is called internally — list is refreshed
            ->assertSet('showTrashModal', true)
            ->assertNotSet('deletedHardware', []);

        // Restored hardware now exists
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'WILL_RESTORE']);

        // Both original created audits still exist (trash list shows historical data)
        $restoredHw = Hardware::where('pc_name', 'WILL_RESTORE')->first();
        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $restoredHw->id,
            'action' => 'rollback',
            'user_id' => $user->id,
        ]);
    }
}
