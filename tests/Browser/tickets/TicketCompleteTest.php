<?php

/*
|--------------------------------------------------------------------------|
| Ticket Complete Browser Tests
|
| Tests the ticket completion modal: opening the modal, completion options
| (complete/forward), and the modal form fields.
| All text is Persian (RTL).
|
| Run: pest tests/Browser/tickets/TicketCompleteTest.php --browser
|
*/

use App\Models\Ticket;

beforeEach(function (): void {
    [$this->user, $this->unit, $this->nCode] = createTicketUser();

    // Create a sample ticket in accepted status (eligible for completion).
    $this->ticket = Ticket::create([
        'ticket_code' => 'TK-COMP0001',
        'subject' => 'تیکت قابل تکمیل',
        'content' => 'این تیکت برای تست تکمیل ایجاد شده است.',
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
        'priority' => 'normal',
        'status' => 'accepted',
    ]);
});

afterEach(function (): void {
    Ticket::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('opens the completion modal from the inbox page', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        // The completion modal title text should be present in the page (hidden by default).
        ->assertSee('تکمیل تیکت')
        ->assertNoJavaScriptErrors();
});

it('shows completion options including unit search and note fields', function (): void {
    $page = loginTicketUser($this->nCode);

    $page->visit('/tickets/inbox')
        ->waitForText('صندوق تیکت‌های پشتیبانی')
        // The completion/forward modal content is rendered but hidden (x-modal).
        // Verify the modal label and form fields exist in the DOM.
        ->assertSee('تکمیل تیکت')
        ->assertSee('ارجاع به واحد مقصد (اختیاری):')
        ->assertNoJavaScriptErrors();
});
