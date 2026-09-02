<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationsBellLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        Cache::flush();
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

    // ==================== Rendering ====================

    public function test_bell_renders_for_authenticated_user(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertStatus(200);
    }

    // ==================== Mount / empty state ====================

    public function test_bell_mounts_with_empty_notifications(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 0)
            ->assertSet('showDropdown', false)
            ->assertViewHas('notifications', fn ($notifications) => $notifications->isEmpty());
    }

    // ==================== Mark as read ====================

    public function test_mark_as_read_updates_notification(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $notif = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست خواندن',
            'body' => 'متن تست',
            'icon' => 'o-bell',
            'color' => 'text-info',
            'is_read' => false,
        ]);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 1)
            ->call('markAsRead', $notif->id)
            ->assertSet('unreadCount', 0);

        $this->assertDatabaseHas('notifications', [
            'id' => $notif->id,
            'is_read' => true,
        ]);
    }

    // ==================== Mark all as read ====================

    public function test_mark_all_as_read_updates_all_notifications(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'اعلان اول',
            'is_read' => false,
        ]);
        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'اعلان دوم',
            'is_read' => false,
        ]);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 2)
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'اعلان اول',
            'is_read' => true,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'اعلان دوم',
            'is_read' => true,
        ]);
    }
}
