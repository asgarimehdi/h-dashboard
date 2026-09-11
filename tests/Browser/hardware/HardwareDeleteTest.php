<?php

/*
|--------------------------------------------------------------------------
| Hardware Delete Browser Tests
|--------------------------------------------------------------------------
|
| Tests deleting hardware: confirmation dialog appearance, cancel
| keeping the record, and confirm deleting it.
|
| wire:confirm in Livewire uses native window.confirm(), which we
| override via JavaScript to simulate user confirmation/cancellation.
|
| Run: pest tests/Browser/hardware/HardwareDeleteTest.php --browser
|
*/

use App\Models\Hardware;

beforeEach(function () {
    Hardware::query()->forceDelete();

    [$this->user, $this->unit, $this->nCode] = createHardwareAdmin();

    $this->hardware = createSampleHardware($this->unit, $this->nCode, [
        'pc_name' => 'PC-Delete-Me',
        'type' => 'desktop',
    ]);
});

afterEach(function () {
    Hardware::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('delete shows confirmation dialog', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Before clicking, override window.confirm to capture the call.
        // We set it to return false so the dialog is "cancelled" and we
        // can verify the confirm text appeared in the page source.
        ->script('window.__confirmCalled = false; window.__confirmMessage = ""; window.confirm = function(msg) { window.__confirmCalled = true; window.__confirmMessage = msg; return false; };')
        // Click the delete (trash) button for our record.
        ->click('[wire\\:click*="delete('.$this->hardware->id.')"]')
        ->wait(1)
        // Verify the confirm dialog was triggered with the expected message.
        ->assertScript('window.__confirmCalled', true)
        ->assertScript('window.__confirmMessage', 'آیا مطمئن هستید؟')
        // The record should still exist (we cancelled the dialog).
        ->assertSee('PC-Delete-Me')
        ->assertNoJavaScriptErrors();
});

it('cancel keeps the record', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Override confirm to return false (simulate clicking Cancel).
        ->script('window.confirm = () => false;')
        // Click delete button.
        ->click('[wire\\:click*="delete('.$this->hardware->id.')"]')
        ->wait(1)
        // The record should still be visible.
        ->assertSee('PC-Delete-Me')
        // Verify it still exists in the database via JS.
        ->assertScript('document.body.innerText.includes("PC-Delete-Me")')
        ->assertNoJavaScriptErrors();
});

it('confirm deletes the record', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Override confirm to return true (simulate clicking Confirm/OK).
        ->script('window.confirm = () => true;')
        // Click delete button.
        ->click('[wire\\:click*="delete('.$this->hardware->id.')"]')
        ->wait(2) // Wait for Livewire delete + table refresh.
        // The record should no longer be visible.
        ->assertDontSee('PC-Delete-Me')
        // Verify it's gone from the page.
        ->assertScript('document.body.innerText.includes("PC-Delete-Me") === false')
        ->assertNoJavaScriptErrors();
});
