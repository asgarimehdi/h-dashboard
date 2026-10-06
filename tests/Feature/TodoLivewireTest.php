<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
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

    // Point editingId at the inaccessible todo (tampered-payload simulation —
    // #[Locked] rejects a plain ->set(), so the snapshot is tampered + resigned)
    tamperEditingId(Livewire::test('todo.todo'), $todo->id)
        ->call('delete');

    $this->assertDatabaseHas('todos', ['id' => $todo->id]);
});

// ==================== #838: save() scope-checks the target row ====================

/**
 * Simulate a tampered client payload pointing editingId at $todoId.
 *
 * $editingId is #[Locked], so a plain ->set() throws before reaching the
 * component — exactly as a real tampered request would. Re-signing the
 * snapshot delivers the forged id to the component, proving the server-side
 * scope-check (not just Locked) refuses the write.
 *
 * @param  Testable  $component
 */
function tamperEditingId($component, int $todoId)
{
    $snapshot = $component->snapshot;
    $snapshot['data']['editingId'] = $todoId;
    unset($snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);
    $component->snapshot = $snapshot;

    return $component;
}

test('cannot save over a todo from an inaccessible unit', function () {
    $this->actingAs($this->user);

    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    $todo = Todo::factory()->create([
        'unit_id' => $otherUnit->id,
        'title' => 'عنوان اصلی',
    ]);

    // Attacker points editingId at the foreign row but submits their own unit —
    // the old guard passed and updateOrCreate rewrote + relocated the row.
    tamperEditingId(Livewire::test('todo.todo'), $todo->id)
        ->set('title', 'عنوان هک‌شده')
        ->set('unit_id', $this->unit->id)
        ->set('start_date_picker', '1405/07/01')
        ->call('save');

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'title' => 'عنوان اصلی',
        'unit_id' => $otherUnit->id,
    ]);
});

test('cannot relocate a todo into the null-unit bucket via save', function () {
    $this->actingAs($this->user);

    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    $todo = Todo::factory()->create([
        'unit_id' => $otherUnit->id,
        'title' => 'عنوان اصلی',
    ]);

    // unit_id = null short-circuited the old truthy-only guard.
    tamperEditingId(Livewire::test('todo.todo'), $todo->id)
        ->set('title', 'عنوان هک‌شده')
        ->set('unit_id', null)
        ->set('start_date_picker', '1405/07/01')
        ->call('save');

    $this->assertDatabaseHas('todos', [
        'id' => $todo->id,
        'title' => 'عنوان اصلی',
        'unit_id' => $otherUnit->id,
    ]);
});

// ==================== #838: null-unit contract (creator-owned) ====================

test('null-unit todo is invisible and untouchable to a non-creator', function () {
    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    $otherPerson = Person::create([
        'n_code' => '1111111111',
        'f_name' => 'تست',
        'l_name' => 'دوم',
        'u_id' => $otherUnit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);
    $otherUser = User::factory()->create(['n_code' => '1111111111']);
    $otherUser->units()->attach($otherUnit->id, ['role' => 'staff', 'is_primary' => true]);
    $otherUser->givePermissionTo('calendar');

    $todo = Todo::factory()->create([
        'unit_id' => null,
        'user_id' => $this->user->id,
        'title' => 'تسک شخصی',
    ]);

    $this->actingAs($otherUser);

    // Not listed in the other user's calendar …
    $events = Livewire::test('todo.todo')->viewData('events');
    $this->assertNotContains('todo-'.$todo->id, array_column($events, 'id'));

    // … not editable …
    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->assertSet('modal', false);

    // … not deletable …
    tamperEditingId(Livewire::test('todo.todo'), $todo->id)
        ->call('delete');
    $this->assertDatabaseHas('todos', ['id' => $todo->id]);

    // … not toggleable.
    $this->assertFalse((bool) $todo->fresh()->is_completed);
    Livewire::test('todo.todo')
        ->call('toggleComplete', $todo->id);
    $this->assertFalse((bool) $todo->fresh()->is_completed);
});

test('creator can list, edit, toggle and delete their own null-unit todo', function () {
    $this->actingAs($this->user);

    $todo = Todo::factory()->create([
        'unit_id' => null,
        'user_id' => $this->user->id,
        'title' => 'تسک شخصی',
    ]);

    // Listed in the creator's own calendar …
    $events = Livewire::test('todo.todo')->viewData('events');
    $this->assertContains('todo-'.$todo->id, array_column($events, 'id'));

    // … editable …
    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->assertSet('modal', true)
        ->set('title', 'عنوان جدید')
        ->set('start_date_picker', '1405/07/01')
        ->call('save')
        ->assertHasNoErrors();
    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'title' => 'عنوان جدید']);

    // … toggleable …
    Livewire::test('todo.todo')
        ->call('toggleComplete', $todo->id);
    $this->assertTrue((bool) $todo->fresh()->is_completed);

    // … deletable (editingId arrives via the legitimate editEvent path).
    Livewire::test('todo.todo')
        ->call('editEvent', $todo->id)
        ->call('delete');
    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
});

// ==================== #847 — ticket task_title scope ====================
//
// `getEvents()` built ticket events with `with('task')` and put
// `$ticket->task?->title` straight into `extendedProps.task_title`. This page
// is gated by `calendar` alone — not a ticket permission at all — so it was
// the cheapest read in the whole chain: a user with only `calendar` received
// the title of a todo in a unit they cannot reach.
// =======================================================================

test('calendar hides a ticket task title from another unit', function () {
    $this->actingAs($this->user);

    $victimUnit = Unit::create(['name' => 'واحد قربانی تقویم']);
    $secretTodo = Todo::factory()->create([
        'unit_id' => $victimUnit->id,
        'title' => 'عنوان محرمانه تقویم',
        'is_completed' => false,
    ]);

    // A ticket the viewer legitimately sees (their own unit) which points at
    // the victim's todo — the legacy shape #847's validation now prevents.
    $ticket = Ticket::create([
        'ticket_code' => 'TKT-847-CAL',
        'user_id' => $this->user->id,
        'unit_id' => $this->unit->id,
        'subject' => 'تیکت تقویم',
        'content' => 'محتوا',
        'priority' => 'normal',
        'status' => 'created',
        'task_id' => $secretTodo->id,
    ]);

    $events = Livewire::test('todo.todo')->instance()->getEvents();

    $event = collect($events)->firstWhere('extendedProps.ticket_code', 'TKT-847-CAL');

    expect($event)->not->toBeNull();
    expect($event['extendedProps']['task_title'])->toBeNull();
    expect($event['extendedProps']['task_id'])->toBeNull();
});

test('calendar still shows a ticket task title inside the viewer scope', function () {
    $this->actingAs($this->user);

    $myTodo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'وظیفه مجاز تقویم',
        'is_completed' => false,
    ]);

    Ticket::create([
        'ticket_code' => 'TKT-847-OK',
        'user_id' => $this->user->id,
        'unit_id' => $this->unit->id,
        'subject' => 'تیکت تقویم مجاز',
        'content' => 'محتوا',
        'priority' => 'normal',
        'status' => 'created',
        'task_id' => $myTodo->id,
    ]);

    $events = Livewire::test('todo.todo')->instance()->getEvents();

    $event = collect($events)->firstWhere('extendedProps.ticket_code', 'TKT-847-OK');

    expect($event)->not->toBeNull();
    expect($event['extendedProps']['task_title'])->toBe('وظیفه مجاز تقویم');
});
