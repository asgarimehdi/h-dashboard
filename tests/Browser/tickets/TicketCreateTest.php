<?php

/*
|--------------------------------------------------------------------------|
| Ticket Create Browser Tests
|
| Tests the ticket creation page (/tickets/new): page load, form fields,
| unit search autocomplete, priority select, subject/content validation.
| All text is Persian (RTL).
|
| Run: pest tests/Browser/tickets/TicketCreateTest.php --browser
|
*/

use App\Models\Ticket;
use App\Models\Unit;

beforeEach(function (): void {
    [$this->user, $this->unit, $this->nCode] = createTicketUser();

    // Create a second unit the user can send tickets to.
    $this->targetUnit = Unit::create([
        'name' => 'واحد دریافت تیکت تست',
        'can_receive_tickets' => true,
        'is_active' => true,
    ]);
});

afterEach(function (): void {
    Ticket::query()->forceDelete();
    if (isset($this->targetUnit)) {
        $this->targetUnit->delete();
    }
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('loads the ticket create page and shows the header', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/new')
        ->waitForText('ایجاد تیکت جدید')
        ->assertSee('ایجاد تیکت جدید')
        ->assertPathIs('/tickets/new')
        ->assertNoJavaScriptErrors();
});

it('shows unit search, priority, subject, and content fields', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/new')
        ->waitForText('ایجاد تیکت جدید')
        // All key form fields should be visible (labels in Persian).
        ->assertSee('واحد گیرنده')
        ->assertSee('سطح فوریت')
        ->assertSee('موضوع تیکت')
        ->assertSee('شرح درخواست')
        ->assertNoJavaScriptErrors();
});

it('shows validation errors when submitting empty form', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/new')
        ->waitForText('ایجاد تیکت جدید')
        // Click submit without filling any required fields.
        ->press('ارسال نهایی')
        ->wait(1)
        // Livewire validation error box should appear.
        ->assertSee('خطا در ثبت تیکت')
        ->assertNoJavaScriptErrors();
});

it('shows validation error when subject has fewer than 5 characters', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/new')
        ->waitForText('ایجاد تیکت جدید')
        ->type('[wire\\:model="subject"]', 'ab')
        ->press('ارسال نهایی')
        ->wait(1)
        // Subject min:5 validation should fire.
        ->assertSee('خطا در ثبت تیکت')
        ->assertNoJavaScriptErrors();
});

it('shows validation error when content has fewer than 10 characters', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/new')
        ->waitForText('ایجاد تیکت جدید')
        ->type('[wire\\:model="subject"]', 'موضوع تست')
        ->type('[wire\\:model="content"]', 'متن کوتاه')
        ->press('ارسال نهایی')
        ->wait(1)
        // Content min:10 validation should fire.
        ->assertSee('خطا در ثبت تیکت')
        ->assertNoJavaScriptErrors();
});
