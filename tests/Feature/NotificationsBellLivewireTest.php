<?php

namespace Tests\Feature;

use App\Models\Notification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class NotificationsBellLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        Cache::flush();
    }

    // ==================== Rendering ====================

    public function test_bell_renders_for_authenticated_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertStatus(200);
    }

    // ==================== Mount / empty state ====================

    public function test_bell_mounts_with_empty_notifications(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 0)
            ->assertSet('showDropdown', false)
            ->assertViewHas('notifications', fn ($notifications) => $notifications->isEmpty());
    }

    // ==================== Mark as read ====================

    public function test_mark_as_read_updates_notification(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
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
        ['user' => $user] = $this->createUserWithUnit();
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
