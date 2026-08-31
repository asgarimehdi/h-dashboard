<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\TestCase;

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

    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('calendar');
    $this->user->givePermissionTo('manage_users');

    Session::put('current_unit_id', $this->unit->id);
});

test('dashboard renders correctly for authenticated user', function () {
    Livewire::actingAs($this->user)
        ->test('dashboard')
        ->assertOk()
        ->assertSee('داشبورد مدیریت اطلاعات سلامت')
        ->assertSee('کل تیکت‌ها');
});

test('todo page renders and allows toggling completion', function () {
    $todo = Todo::create([
        'title' => 'تست کار روزانه',
        'start_at' => now(),
        'is_completed' => false,
        'unit_id' => $this->unit->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('todo.todo')
        ->assertOk()
        ->assertSee('تقویم سازمانی');

    Livewire::actingAs($this->user)
        ->test('todo.todo')
        ->call('toggleComplete', $todo->id);

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'is_completed' => true,
    ]);
});

test('todo page allows creating new todo', function () {
    Livewire::actingAs($this->user)
        ->test('todo.todo')
        ->set('title', 'کار جدید')
        ->set('start_date_picker', Jalalian::now()->format('Y/m/d'))
        ->call('save');

    $this->assertDatabaseHas('todos', [
        'title' => 'کار جدید',
        'unit_id' => $this->unit->id,
    ]);
});

test('notification bell shows unread count and marks as read', function () {
    NotificationService::send($this->user->id, 'info', 'عنوان تست', 'متن تست');

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->assertOk()
        ->assertSee('1'); // Unread count

    Livewire::actingAs($this->user)
        ->test('notifications.bell')
        ->call('markAllAsRead');

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->user->id,
        'is_read' => true,
    ]);
});

test('activity log page renders and respects manage_users permission', function () {
    $this->actingAs($this->user);
    ActivityLogService::login();

    Livewire::actingAs($this->user)
        ->test('activity-log.index')
        ->assertOk()
        ->assertSee('ورود');
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
