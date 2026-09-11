<?php

/*
|--------------------------------------------------------------------------|
| Todo Create Browser Tests
|
| Tests the todo page (/todo): page load, create button opens modal,
| form fields (title, dates, priority/toggle), and empty-form validation.
| All text is Persian (RTL).
|
| Run: pest tests/Browser/todo/TodoCreateTest.php --browser
|
*/

use App\Models\Todo;

beforeEach(function (): void {
    [$this->user, $this->unit, $this->nCode] = createTodoUser();
});

afterEach(function (): void {
    Todo::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('loads the todo page and shows the calendar header', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        ->assertSee('تقویم سازمانی')
        ->assertPathIs('/todo')
        ->assertNoJavaScriptErrors();
});

it('opens the create modal when clicking the new task button', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        // Click the "تسک جدید" (New Task) button.
        ->click('تسک جدید')
        ->wait(1)
        // The modal should open with the form.
        ->assertSee('جزئیات تسک')
        ->assertSee('ذخیره')
        ->assertNoJavaScriptErrors();
});

it('shows title, dates, and completion toggle in the create form', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        ->click('تسک جدید')
        ->wait(1)
        // Form fields should be visible.
        ->assertSee('عنوان فعالیت')
        ->assertSee('تاریخ و ساعت شروع')
        ->assertSee('تاریخ و ساعت پایان')
        ->assertSee('انجام شده')
        ->assertNoJavaScriptErrors();
});

it('shows validation errors when submitting empty form', function (): void {
    $page = loginTodoUser($this->nCode);

    $page->visit('/todo')
        ->waitForText('تقویم سازمانی')
        ->click('تسک جدید')
        ->wait(1)
        // Submit without filling required fields.
        ->press('ذخیره')
        ->wait(1)
        // The modal should remain open (validation failed).
        ->assertSee('جزئیات تسک')
        ->assertNoJavaScriptErrors();
});
