<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Unit;
use App\Models\Person;
use App\Models\Todo;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    
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
    
    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('calendar');
    $this->user->givePermissionTo('manage_users');
    
    Session::put('current_unit_id', $this->unit->id);
});

test('dashboard renders correctly for authenticated user', function () {
    Livewire::actingAs($this->user)
        ->test('dashboard')
        ->assertOk()
        ->assertSee($this->user->name)
        ->assertSee($this->unit->name);
});

test('todo page renders and allows toggling completion', function () {
    $todo = Todo::create([
        'title' => 'تست کار روزانه',
        'is_completed' => false,
        'unit_id' => $this->unit->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('todo.todo')
        ->assertOk()
        ->assertSee('تست کار روزانه');

    Livewire::actingAs($this->user)
        ->test('todo.todo')
        ->call('toggle', $todo->id);

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'is_completed' => true,
    ]);
});

test('todo page allows creating new todo', function () {
    Livewire::actingAs($this->user)
        ->test('todo.todo')
        ->set('title', 'کار جدید')
        ->call('addTodo'); // Assuming method name is addTodo based on typical patterns

    $this->assertDatabaseHas('todos', [
        'title' => 'کار جدید',
        'unit_id' => $this->unit->id,
    ]);
});

test('notification bell shows unread count and marks as read', function () {
    NotificationService::send($this->user, 'عنوان تست', 'متن تست', 'info');

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertOk()
        ->assertSee('1'); // Unread count

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->call('markAsRead'); // Assuming method name

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'is_read' => true,
    ]);
});

test('activity log page renders and respects manage_users permission', function () {
    ActivityLogService::login($this->user);

    Livewire::actingAs($this->user)
        ->test('activity-log.index')
        ->assertOk()
        ->assertSee('login');

    $guestUser = User::factory()->create();
    Livewire::actingAs($guestUser)
        ->test('activity-log.index')
        ->assertStatus(403);
});

test('route protection for todo and activity-log pages', function () {
    $noPermUser = User::factory()->create();
    
    // Todo page requires 'calendar'
    $this->actingAs($noPermUser)
        ->get('/todo')
        ->assertStatus(403);

    // Activity log requires 'manage_users'
    $this->actingAs($noPermUser)
        ->get('/activity-log')
        ->assertStatus(403);
});
