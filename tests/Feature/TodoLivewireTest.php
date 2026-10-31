<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Issue #1169: the first paint is bounded to the visible month grid and
 * `TodoFactory` back-dates up to a month, so a test that only cares about
 * scoping/visibility must ask for a window wide enough to include every
 * factory row instead of relying on an unbounded `getEvents()`.
 */
function calendarEventsForWideWindow(): array
{
    $component = Livewire::test('todo.todo')->instance();
    $component->fetchEvents(
        now()->subYears(2)->startOfDay()->toIso8601String(),
        now()->addYears(2)->endOfDay()->toIso8601String(),
    );

    return $component->getEvents();
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->seedLookupTables();

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
        // #1169: the initial payload is bounded to the visible month now, and
        // the factory back-dates up to a month — pin the date inside the
        // window so this visibility assertion is not date-dependent.
        'start_at' => now()->toDateTimeString(),
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

    $events = calendarEventsForWideWindow();

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

    $events = calendarEventsForWideWindow();

    $event = collect($events)->firstWhere('extendedProps.ticket_code', 'TKT-847-OK');

    expect($event)->not->toBeNull();
    expect($event['extendedProps']['task_title'])->toBe('وظیفه مجاز تقویم');
});

// ==================== Issue #891 — stored XSS in the calendar sink ====================

/**
 * Issue #891: `eventContent` returned `{ html: ... }`, which FullCalendar
 * assigns through `dangerouslySetInnerHTML`. Todo titles — and `Ticket::subject`
 * via `saveTicket()` on the ticket-create page — were concatenated straight
 * into that HTML string, so any calendar viewer executed markup a
 * `create_ticket` holder could plant. `create_ticket` alone was enough to
 * store it, because that page copies the subject into a new `Todo::title`.
 *
 * This asserts the ABSENCE of the raw-HTML path in the view. It cannot observe
 * what a browser does with a string; the behavioural assertion that no injected
 * node appears is in tests/e2e/todo/todo.spec.ts, where a real browser runs.
 * Escaping in the validation layer is deliberately NOT a control here — the
 * sink is client-side, so hardening the rule would not close it.
 */
test('calendar eventContent does not return the raw html injection path', function () {
    $view = file_get_contents(resource_path('views/livewire/todo/todo.blade.php'));

    expect($view)->not->toContain('html:');
});

test('calendar eventContent builds nodes with textContent instead of html', function () {
    $view = file_get_contents(resource_path('views/livewire/todo/todo.blade.php'));

    // domNodes + textContent is the shape that cannot resurrect the bug: there
    // is no string left to escape correctly, so the class of defect cannot recur.
    expect($view)->toContain('domNodes:');
    expect($view)->toContain('textContent');
    expect($view)->toContain('document.createElement');
});

test('calendar completion toggle does not rely on an inline onclick handler', function () {
    $view = file_get_contents(resource_path('views/livewire/todo/todo.blade.php'));

    // The icon markup is static and carries no user data, but the inline
    // handler is exactly what the report-only CSP's 'unsafe-inline' allows.
    // Replacing it with addEventListener costs nothing while the lines are open.
    expect($view)->not->toContain('onclick=');
    expect($view)->toContain('addEventListener');
});

/**
 * A todo title is stored verbatim and must reach the calendar as DATA. Nothing
 * in the component may escape or strip it — that would be the wrong layer, and
 * it would also make this test's expectation of verbatim text a lie.
 */
test('a todo title containing markup is stored verbatim and reaches the events payload', function () {
    $this->actingAs($this->user);

    $title = '<img src=x onerror=alert(1)>';
    $todo = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => $title,
        'is_completed' => false,
    ]);

    expect($todo->fresh()->title)->toBe($title);

    $events = calendarEventsForWideWindow();
    $event = collect($events)->firstWhere('id', 'todo-'.$todo->id);

    expect($event)->not->toBeNull();
    expect($event['title'])->toBe($title);
});

// ==================== Issue #982 — date-range bounded calendar events ====================

/**
 * Issue #982: The calendar's event loading was gated on a non-existent
 * `hasPubliclyVisibleDates` property, so `fetchEvents()` never ran when the
 * user navigated months. The initial unbounded load (cache key `:all::all`)
 * became the permanent payload — all todos and open tickets loaded forever.
 *
 * This test ensures:
 * 1. Events are filtered by the provided date range (both todos and ticket-tasks)
 * 2. Cache keys differ across ranges so narrow views don't collapse to `:all::all`
 */
test('calendar events are bounded by the provided date range', function () {
    $this->actingAs($this->user);

    // Create todos in different months
    $todoInRange = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'در بازه',
        'start_at' => '2026-07-15 10:00:00', // Middle of the range
        'is_completed' => false,
    ]);

    $todoBeforeRange = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'قبل از بازه',
        'start_at' => '2026-06-15 10:00:00', // Before the range
        'is_completed' => false,
    ]);

    $todoAfterRange = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'بعد از بازه',
        'start_at' => '2026-08-15 10:00:00', // After the range
        'is_completed' => false,
    ]);

    // Test the component instance directly with a specific date range
    $component = Livewire::test('todo.todo')->instance();

    // Use fetchEvents which sets the private calendarStart/calendarEnd
    $component->fetchEvents('2026-07-01 00:00:00', '2026-07-31 23:59:59');

    $events = $component->getEvents();

    // Only the todo in July should be present
    $eventIds = array_column($events, 'id');

    expect($eventIds)->toContain('todo-'.$todoInRange->id);
    expect($eventIds)->not->toContain('todo-'.$todoBeforeRange->id);
    expect($eventIds)->not->toContain('todo-'.$todoAfterRange->id);
});

test('calendar cache key differs across date ranges', function () {
    $this->actingAs($this->user);

    Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'تسک تست',
        'start_at' => '2026-07-15 10:00:00',
    ]);

    $component = Livewire::test('todo.todo')->instance();

    // Range 1: July 2026
    $component->fetchEvents('2026-07-01 00:00:00', '2026-07-31 23:59:59');
    $events1 = $component->getEvents();

    // Range 2: August 2026
    $component->fetchEvents('2026-08-01 00:00:00', '2026-08-31 23:59:59');
    $events2 = $component->getEvents();

    // The events should be different (empty for August since we only have July data)
    expect($events1)->not->toBeEmpty();
    expect($events2)->toBeEmpty();
});

// ==================== Issue #1169 — the todo calendar's first render is bounded ====================

/**
 * Issue #1169: `mount()` seeds the window the month grid actually shows, so
 * the first paint no longer queries every todo and ticket ever created. Both
 * rows below exist in the database — only the in-window one may reach the
 * payload, which is what makes the first paint bounded rather than merely
 * smaller.
 */
test('initial calendar payload only contains todos inside the seeded month window', function () {
    $this->actingAs($this->user);
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $inWindow = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'وظیفه داخل پنجره',
        'start_at' => '2026-10-10 10:00:00',
    ]);

    $outOfWindow = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'وظیفه خارج پنجره',
        'start_at' => '2026-07-10 10:00:00',
    ]);

    $eventIds = array_column(Livewire::test('todo.todo')->instance()->getEvents(), 'id');

    expect($eventIds)->toContain('todo-'.$inWindow->id);
    expect($eventIds)->not->toContain('todo-'.$outOfWindow->id);

    // Both rows exist: the absence above is the query window, not a missing row.
    $this->assertDatabaseHas('todos', ['id' => $inWindow->id]);
    $this->assertDatabaseHas('todos', ['id' => $outOfWindow->id]);
});

/**
 * The `:all:` fallback key was the visible symptom of the unbounded first
 * render: a null range made every request share one cache entry holding
 * every row. `KeyWritten` is observed through the cache store itself, so the
 * assertion covers the key the component really wrote.
 */
test('initial calendar cache key is range-scoped and never falls back to all', function () {
    $this->actingAs($this->user);
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    Event::fake([KeyWritten::class]);

    Livewire::test('todo.todo');

    Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event) => str_starts_with($event->key, 'calendar:')
        && ! str_contains($event->key, ':all:'));
    Event::assertNotDispatched(KeyWritten::class, fn (KeyWritten $event) => str_contains($event->key, ':all:'));
});

/**
 * The refetch guard lives in the Blade `datesSet` handler, so it is asserted
 * structurally here and smoke-tested in a browser in the PR (no JS test
 * harness exists in this suite). A bare unconditional call is exactly what
 * #1169 removed.
 */
test('calendar datesSet guards the refetch with the seeded window', function () {
    $view = file_get_contents(resource_path('views/livewire/todo/todo.blade.php'));

    preg_match('/datesSet:\s*function\s*\(info\)\s*\{(?<body>.*?)\n\s{12}\},/s', $view, $matches);

    expect($matches)->not->toBeEmpty();

    $handler = $matches['body'];

    // Real navigation (month/week/day) still refetches …
    expect($handler)->toContain('fetchEvents');
    // … but only after comparing the incoming range with the seeded window,
    // and only for the very first callback.
    expect($handler)->toContain('firstPaint');
    expect($handler)->toContain('Date.parse');
    expect($handler)->toContain('return');
});

/**
 * #1169 correction: the seeded window must sit on the same week boundaries as
 * FullCalendar's grid (`firstDay: 6` = Saturday) — the Saturday on/before the
 * 1st through the Saturday after the last day. A `subWeek()` window misses
 * those boundaries and the redundant first refetch comes back.
 */
test('calendar window is aligned to the Saturday grid boundaries', function () {
    $this->actingAs($this->user);

    $component = Livewire::test('todo.todo')->instance();

    $cases = [
        '2026-07-15' => ['2026-06-27', '2026-08-01'],
        '2026-08-15' => ['2026-08-01', '2026-09-05'],
        '2026-10-15' => ['2026-09-26', '2026-11-07'],
        '2027-02-15' => ['2027-01-30', '2027-03-06'],
    ];

    foreach ($cases as $month => [$expectedStart, $expectedEnd]) {
        [$start, $end] = $component->calendarWindowFor(Carbon::parse($month));

        expect($start)->toBe(Carbon::parse($expectedStart)->toIso8601String());
        expect($end)->toBe(Carbon::parse($expectedEnd)->toIso8601String());
        expect(Carbon::parse($start)->dayOfWeek)->toBe(Carbon::SATURDAY);
        expect(Carbon::parse($end)->dayOfWeek)->toBe(Carbon::SATURDAY);
        expect(Carbon::parse($start)->lessThanOrEqualTo(Carbon::parse($month)->startOfMonth()))->toBeTrue();
        expect(Carbon::parse($end)->greaterThan(Carbon::parse($month)->endOfMonth()))->toBeTrue();
    }
});

/**
 * Navigation is the supported refetch path and must keep working with the
 * seeded range in place — including a cache entry per window and the original
 * window's entry surviving the neighbour's fetch.
 */
test('navigating to the next month fetches that month with its own cache entry', function () {
    $this->actingAs($this->user);
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $october = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'وظیفه مهر',
        'start_at' => '2026-10-20 10:00:00',
    ]);

    $november = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'وظیفه آبان',
        'start_at' => '2026-11-10 10:00:00',
    ]);

    $component = Livewire::test('todo.todo')->instance();

    // The seeded window is October's grid: November is outside it.
    expect(array_column($component->getEvents(), 'id'))
        ->toContain('todo-'.$october->id)
        ->not->toContain('todo-'.$november->id);

    [$novStart, $novEnd] = $component->calendarWindowFor(Carbon::parse('2026-11-15'));
    $component->fetchEvents($novStart, $novEnd);

    expect(array_column($component->getEvents(), 'id'))
        ->toContain('todo-'.$november->id)
        ->not->toContain('todo-'.$october->id);

    // A row inserted into October after the November fetch must still be
    // missing when October is requested again: November did not clobber and
    // then re-query October's cache entry.
    $lateOctober = Todo::factory()->create([
        'unit_id' => $this->unit->id,
        'title' => 'وظیفه دیررس مهر',
        'start_at' => '2026-10-22 10:00:00',
    ]);

    [$octStart, $octEnd] = $component->calendarWindowFor(Carbon::parse('2026-10-15'));
    $component->fetchEvents($octStart, $octEnd);

    expect(array_column($component->getEvents(), 'id'))
        ->toContain('todo-'.$october->id)
        ->not->toContain('todo-'.$lateOctober->id);
});

test('rendered calendar receives the seeded window for its first datesSet', function () {
    $this->actingAs($this->user);
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $component = Livewire::test('todo.todo');

    [$start, $end] = $component->instance()->calendarWindowFor(Carbon::parse('2026-10-15'));

    // Without these two values in the payload the client-side guard cannot
    // recognise the first callback and #1169's redundant refetch returns.
    expect($component->html())
        ->toContain($start)
        ->toContain($end);
});

test('dead guard property hasPubliclyVisibleDates is absent from the codebase', function () {
    $view = file_get_contents(resource_path('views/livewire/todo/todo.blade.php'));

    // The property should not appear anywhere in the file
    expect($view)->not->toContain('hasPubliclyVisibleDates');
});
