# Plan 006: Fix Todo Calendar Drag-Drop Creates Instead of Updating

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Medium  
**Category:** Bug Fix  

## Problem

When a user drags a todo event on the FullCalendar to a new date, the `eventDrop` handler calls `openCreateModal()` instead of updating the existing event's dates. This creates a new todo via the modal instead of updating the dragged event in-place.

## Current State

**File:** `resources/views/livewire/todo/todo.blade.php:498-503`

```javascript
eventDrop: function(info) {
    const type = info.event.extendedProps.type;
    if (type === 'todo') {
        @this.openCreateModal(info.event.startStr, info.event.endStr);
    }
}
```

The `eventClick` handler (line 490-497) correctly routes to `editEvent()`:

```javascript
eventClick: function(info) {
    const type = info.event.extendedProps.type;
    if (type === 'ticket') {
        window.location.href = '/tickets/inbox';
    } else {
        @this.editEvent(info.event.id.replace('todo-', ''));
    }
}
```

**Root cause:** The `eventDrop` handler was written to open the create modal (perhaps as a placeholder) and never updated to call an actual update method.

## Proposed Fix

1. Add a new Livewire method `updateTodoDates($todoId, $startDate, $endDate)` to the component
2. Wire the `eventDrop` handler to call this method

### New Livewire method (add to the component class at top of the Blade file):

```php
public function updateTodoDates(string $todoId, string $startDate, ?string $endDate = null): void
{
    $todo = \App\Models\Todo::findOrFail($todoId);
    
    // Check if user has access to this todo's unit
    $accessibleIds = app(AccessService::class)->accessibleUnitIds();
    if (!in_array($todo->unit_id, $accessibleIds)) {
        return;
    }

    $todo->update([
        'start_date' => $startDate,
        'end_date' => $endDate ?? $startDate,
    ]);

    // Bump calendar cache
    \Cache::increment('calendar_version');

    $this->dispatch('swal', ['title' => 'تاریخ وظیفه به‌روزرسانی شد', 'icon' => 'success']);
    $this->mount(); // Refresh the calendar
}
```

### Updated eventDrop handler:

```javascript
eventDrop: function(info) {
    const type = info.event.extendedProps.type;
    if (type === 'todo') {
        const todoId = info.event.id.replace('todo-', '');
        @this.updateTodoDates(todoId, info.event.startStr, info.event.endStr);
    }
}
```

## Files to Modify

| File | Lines | Change |
|------|-------|--------|
| `resources/views/livewire/todo/todo.blade.php` | 498-503 | Replace `openCreateModal` with `updateTodoDates` call |
| `resources/views/livewire/todo/todo.blade.php` | (component class) | Add `updateTodoDates()` method |

**Out of scope:** Ticket events on calendar (they redirect to inbox, which is correct).

## Verification

```bash
# 1. Run todo tests
composer test -- --filter="todo"
# Expected: all pass

# 2. Manual test:
# - Open calendar page
# - Drag a todo event to a new date
# - Verify: existing todo dates updated, no new todo created
# - Verify: calendar refreshes showing the moved event

# 3. Check for duplicate todos created by the old bug
php scripts/boost_tool.php query '{"sql": "SELECT COUNT(*) as cnt FROM todos WHERE created_at > '\''2026-08-01'\''"}'
```

## Test Plan

```php
it('updates todo dates when dragged on calendar instead of creating new', function () {
    $user = User::factory()->create();
    $todo = Todo::factory()->create([
        'unit_id' => $user->person?->u_id ?? Unit::factory()->create()->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    Livewire::actingAs($user)
        ->test('todo.todo')
        ->call('updateTodoDates', $todo->id, '2026-09-15', '2026-09-16');

    $todo->refresh();
    expect($todo->start_date)->toBe('2026-09-15')
        ->and($todo->end_date)->toBe('2026-09-16');

    // Ensure no duplicate was created
    expect(Todo::where('title', $todo->title)->count())->toBe(1);
});
```

## STOP Conditions

- If `Todo` model has different column names for dates (check migration)
- If the calendar uses a different ID format than `'todo-{id}'`
- If `AccessService` is not available in the todo component scope

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Todo date columns are named differently | Query fails | Check migration for actual column names |
| All-day events have different date format | Parsing error | Verify FullCalendar's `startStr` format |
| User has no unit context | Empty accessible IDs | Check `session('current_unit_id')` is set |

## Maintenance Notes

- Consider adding drag-and-drop for ticket events too (move to different unit)
- The `updateTodoDates` method could be generalized to update any todo field from calendar
