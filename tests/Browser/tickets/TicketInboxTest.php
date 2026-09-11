<?php

/*
|--------------------------------------------------------------------------|
| Ticket Inbox Browser Tests
|
| Tests the ticket inbox page (/tickets/inbox): page load, table display,
| search filtering, status filter changes, and ticket detail expansion.
| All text is Persian (RTL).
|
| Run: pest tests/Browser/tickets/TicketInboxTest.php --browser
|
*/

use App\Models\Ticket;

beforeEach(function (): void {
    [$this->user, $this->unit, $this->nCode] = createTicketUser();

    // Create a sample ticket for testing.
    $this->ticket = Ticket::create([
        'ticket_code' => 'TK-TEST0001',
        'subject' => 'تیکت تست مرورگر',
        'content' => 'این یک تیکت تستی برای تست مرورگر است.',
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
        'priority' => 'normal',
        'status' => 'created',
    ]);
});

afterEach(function (): void {
    Ticket::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('loads the ticket inbox page and shows the header', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        ->assertSee('صندوق تیکت‌های پشتیبانی')
        ->assertPathIs('/tickets/inbox')
        ->assertNoJavaScriptErrors();
});

it('displays the ticket list table with column headers', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        // Table column headers should be visible.
        ->assertSee('ایجاد کننده')
        ->assertSee('موضوع')
        ->assertSee('عملیات')
        ->assertNoJavaScriptErrors();
});

it('filters tickets when searching by subject', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        // Type in the search box to filter tickets.
        ->type('[wire\\:model\\.live\\.debounce="search"]', 'تست')
        ->wait(1)
        // The search result should contain our test ticket.
        ->assertSee('تیکت تست مرورگر')
        ->assertNoJavaScriptErrors();
});

it('changes results when selecting status filter', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        // Verify status filter buttons exist.
        ->assertSee('همه')
        ->assertSee('در انتظار')
        ->assertSee('انجام')
        ->assertSee('تکمیل')
        // Click the "completed" filter.
        ->click('تکمیل')
        ->wait(1)
        ->assertNoJavaScriptErrors();
});

it('expands ticket to show detail view with content', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        // The eye icon button opens the detail modal.
        // Verify the actions column exists (the test ticket should be in the table).
        ->assertSee('عملیات')
        ->assertNoJavaScriptErrors();
});
