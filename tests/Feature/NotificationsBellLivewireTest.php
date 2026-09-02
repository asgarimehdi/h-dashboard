<?php

use App\Models\Notification;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(\App\Models\Notification::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $this->user = User::factory()->create([
        'n_code' => $this->person->n_code,
    ]);

    Session::put('current_unit_id', $this->unit->id);
});

test('notification bell mounts and shows no notifications for new user', function () {
    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertOk()
        ->assertSet('unreadCount', 0)
        ->assertSet('showDropdown', false);
});

test('notification bell shows unread count when notifications exist', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'اعلان تست',
        'body' => 'متن تست',
        'icon' => 'o-info-circle',
        'color' => 'text-info',
        'url' => '/dashboard',
        'is_read' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertOk()
        ->assertSet('unreadCount', 1);
});

test('notification bell shows multiple unread count', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'اعلان اول',
        'icon' => 'o-info-circle',
        'color' => 'text-info',
        'is_read' => false,
    ]);
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'warning',
        'title' => 'اعلان دوم',
        'icon' => 'o-exclamation-triangle',
        'color' => 'text-warning',
        'is_read' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertSet('unreadCount', 2)
        ->assertSee('2');
});

test('toggleDropdown toggles dropdown visibility', function () {
    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertSet('showDropdown', false)
        ->call('toggleDropdown')
        ->assertSet('showDropdown', true)
        ->call('toggleDropdown')
        ->assertSet('showDropdown', false);
});

test('markAsRead marks a single notification as read', function () {
    $notification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'اعلان تست',
        'icon' => 'o-info-circle',
        'color' => 'text-info',
        'is_read' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertSet('unreadCount', 1)
        ->call('markAsRead', $notification->id)
        ->assertSet('unreadCount', 0);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'is_read' => true,
    ]);
});

test('markAllAsRead marks all notifications as read', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'اعلان اول',
        'icon' => 'o-info-circle',
        'color' => 'text-info',
        'is_read' => false,
    ]);
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'warning',
        'title' => 'اعلان دوم',
        'icon' => 'o-exclamation-triangle',
        'color' => 'text-warning',
        'is_read' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertSet('unreadCount', 2)
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'is_read' => true,
    ]);

    // All should be read now
    $this->assertDatabaseCount('notifications', 2);
    $this->assertEquals(0, Notification::where('user_id', $this->user->id)->where('is_read', false)->count());
});

test('notifications are limited to 15', function () {
    // Create 20 notifications
    for ($i = 0; $i < 20; $i++) {
        Notification::create([
            'user_id' => $this->user->id,
            'type' => 'info',
            'title' => "اعلان {$i}",
            'icon' => 'o-info-circle',
            'color' => 'text-info',
            'is_read' => false,
        ]);
    }

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertSet('unreadCount', 20) // all are unread
        ->assertSet('notifications', fn ($n) => count($n) === 15); // but list limited to 15
});

test('markAsRead only affects own notifications', function () {
    $otherUser = User::factory()->create();

    $ownNotification = Notification::create([
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'اعلان من',
        'icon' => 'o-info-circle',
        'color' => 'text-info',
        'is_read' => false,
    ]);

    $otherNotification = Notification::create([
        'user_id' => $otherUser->id,
        'type' => 'info',
        'title' => 'اعلان دیگری',
        'icon' => 'o-info-circle',
        'color' => 'text-info',
        'is_read' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->call('markAsRead', $ownNotification->id);

    // Own should be read
    $this->assertDatabaseHas('notifications', ['id' => $ownNotification->id, 'is_read' => true]);
    // Other's should still be unread
    $this->assertDatabaseHas('notifications', ['id' => $otherNotification->id, 'is_read' => false]);
});

test('notifications dropdown shows notification content', function () {
    Notification::create([
        'user_id' => $this->user->id,
        'type' => 'info',
        'title' => 'تیکت جدید',
        'body' => 'تیکت جدیدی ایجاد شد',
        'icon' => 'o-information-circle',
        'color' => 'text-info',
        'url' => '/tickets',
        'is_read' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->call('toggleDropdown')
        ->assertSee('تیکت جدید')
        ->assertSee('تیکت جدیدی ایجاد شد');
});
