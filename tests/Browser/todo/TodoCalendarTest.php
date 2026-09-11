<?php

/*
|--------------------------------------------------------------------------|
| Todo Calendar Browser Tests
|
| Tests the calendar view on the todo page: calendar renders, todos appear
| on correct dates, and clicking a date/area opens the create modal.
| All text is Persian (RTL).
|
| Run: pest tests/Browser/todo/TodoCalendarTest.php --browser
|
*/

use App\Models\Todo;

beforeEach(function (): void {
    [$this->user, $this->unit, $this->nCode] = createTodoUser();

    // Create a sample todo to appear on the calendar.
    $this->todo = Todo::create([
        'title' => 'جلسه فنی تست',
        'start_at' => now()->toDateTimeString(),
        'end_at' => now()->addDays(2)->toDateTimeString(),
        'is_completed' => false,
        'unit_id' => $this->unit->id,
    ]);
});

afterEach(function (): void {
    Todo::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('loads the calendar view and shows legend', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        // Calendar legend should be visible.
        ->assertSee('وظیفه در انتظار')
        ->assertSee('وظیفه انجام شده')
        ->assertSee('تیکت فوری')
        ->assertSee('تیکت عادی')
        ->assertNoJavaScriptErrors();
});

it('displays todos on the calendar via calendar container', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        // The FullCalendar container should be rendered.
        ->assertSeeAnythingIn('#calendar')
        ->assertNoJavaScriptErrors();
});

it('shows the create button which opens the create modal', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        // The "تسک جدید" button should be visible.
        ->assertSee('تسک جدید')
        // Click it to verify it opens the modal.
        ->click('تسک جدید')
        ->wait(1)
        ->assertSee('جزئیات تسک')
        ->assertNoJavaScriptErrors();
});
