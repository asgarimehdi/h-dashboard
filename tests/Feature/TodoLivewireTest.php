<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
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

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
    $this->user->givePermissionTo('calendar');
});

// ==================== Page Load ====================

test('guest is redirected from todo page', function () {
    $this->get('/todo')->assertRedirect('/login');
});

test('todo page loads for authorized user', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->assertStatus(200);
});

test('todo page shows calendar events', function () {
    $this->actingAs($this->user);

    // Create a todo in user's unit
    Todo::factory()->create(['unit_id' => $this->unit->id]);

    // FullCalendar renders events via JS, so we check the component mounts
    // and the events data is available in the component state
    Livewire::test('todo.todo')
        ->assertStatus(200)
        ->assertViewHas('events');
});

// ==================== Modal ====================

test('open modal resets form fields', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->set('title', 'test')
        ->call('openModal')
        ->assertSet('title', '')
        ->assertSet('editingId', null)
        ->assertSet('modal', true);
});

test('open create modal sets start and end dates', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->call('openCreateModal', '2026-10-01', '2026-10-05')
        ->assertSet('modal', true)
        ->assertSet('start_at', '2026-10-01')
        ->assertSet('end_at', '2026-10-05');
});

test('close modal resets form', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->set('title', 'test')
        ->call('openModal')
        ->call('closeModal')
        ->assertSet('modal', false)
        ->assertSet('title', '');
});

// ==================== Create Todo ====================

test('can create a todo', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->call('openModal')
        ->set('title', 'وظیفه تستی')
        ->set('start_date_picker', '1405/07/01')
        ->set('start_time_picker', '09:00')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('todos', ['title' => 'وظیفه تستی', 'user_id' => $this->user->id]);
});

test('create todo requires title', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->call('openModal')
        ->set('title', '')
        ->set('start_date_picker', '1405/07/01')
        ->call('save')
        ->assertHasErrors(['title']);
});

test('create todo requires start_date_picker', function () {
    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->call('openModal')
        ->set('title', 'وظیفه تستی')
        ->set('start_date_picker', '')
        ->call('save')
        ->assertHasErrors(['start_date_picker']);
});

// ==================== Edit Todo ====================

test('can edit a todo', function () {
    $this->actingAs($this->user);

    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'عنوان اصلی',
    ]);

    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->assertSet('editingId', $todo->id)
        ->assertSet('title', 'عنوان اصلی')
        ->assertSet('modal', true);
});

test('edit saves changes', function () {
    $this->actingAs($this->user);

    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'عنوان قبل',
    ]);

    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->set('title', 'عنوان جدید')
        ->set('start_date_picker', '1405/07/01')
        ->call('save');

    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'title' => 'عنوان جدید']);
});

test('editing a todo preserves the original owner', function () {
    $owner = User::factory()->create(['n_code' => '0987654321']);

    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'عنوان قبل',
        'user_id' => $owner->id,
    ]);

    $this->actingAs($this->user);

    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->set('title', 'عنوان جدید')
        ->set('start_date_picker', '1405/07/01')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'user_id' => $owner->id]);
});

// ==================== Delete Todo ====================

test('can delete a todo', function () {
    $this->actingAs($this->user);

    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'قابل حذف',
    ]);

    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->call('delete')
        ->assertSet('modal', false);

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
});

// ==================== Toggle Complete ====================

test('can toggle todo completion', function () {
    $this->actingAs($this->user);

    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'is_completed' => false,
    ]);

    Livewire::test('todo.todo')
        ->call('toggleComplete', $todo->id);

    $todo->refresh();
    $this->assertTrue($todo->is_completed);
});

test('toggle complete on completed todo marks incomplete', function () {
    $this->actingAs($this->user);

    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'is_completed' => true,
    ]);

    Livewire::test('todo.todo')
        ->call('toggleComplete', $todo->id);

    $todo->refresh();
    $this->assertFalse($todo->is_completed);
});

// ==================== Unit Scope ====================

test('todos are scoped to accessible units', function () {
    $this->actingAs($this->user);

    // Create todo in user's unit
    Todo::factory()->create(['unit_id' => $this->unit->id, 'title' => 'تسک واحد من']);

    // Create todo in another unit (not accessible)
    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    Todo::factory()->create(['unit_id' => $otherUnit->id, 'title' => 'تسک واحد دیگر']);

    // Verify accessible unit IDs don't include the other unit
    $accessibleIds = app(AccessService::class)->accessibleUnitIds($this->user);
    $this->assertContains($this->unit->id, $accessibleIds);
    $this->assertNotContains($otherUnit->id, $accessibleIds);

    // Verify the component loads (FullCalendar renders via JS)
    Livewire::test('todo.todo')
        ->assertStatus(200);
});

test('cannot edit todo from inaccessible unit', function () {
    $this->actingAs($this->user);

    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    $todo = Todo::factory()->create([
        'unit_id' => $otherUnit->id,
        'title' => 'تسک غیرمجاز',
    ]);

    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->assertSet('modal', false);
});

test('cannot delete todo from inaccessible unit', function () {
    $this->actingAs($this->user);

    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    $todo = Todo::factory()->create([
        'unit_id' => $otherUnit->id,
        'title' => 'تسک غیرمجاز',
    ]);

    // Set editingId to the inaccessible todo
    Livewire::test('todo.todo')
        ->set('editingId', $todo->id)
        ->call('delete');

    $this->assertDatabaseHas('todos', ['id' => $todo->id]);
});
