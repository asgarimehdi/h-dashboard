<?php

/*
|--------------------------------------------------------------------------
| Hardware Edit Browser Tests
|--------------------------------------------------------------------------
|
| Tests editing hardware: modal opening, pre-filled data display,
| field modification, and record update on submit.
|
| Run: pest tests/Browser/hardware/HardwareEditTest.php --browser
|
*/

use App\Models\Hardware;

beforeEach(function () {
    Hardware::query()->forceDelete();

    [$this->user, $this->unit, $this->nCode] = createHardwareAdmin();

    $this->hardware = createSampleHardware($this->unit, $this->nCode, [
        'pc_name' => 'PC-Edit-Orig',
        'type' => 'desktop',
        'os' => 'Windows 10',
        'cpu' => 'Intel i5-10400',
        'ram' => '8192',
        'hdd' => 'SSD 256GB',
    ]);
});

afterEach(function () {
    Hardware::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('edit button opens modal', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Click the edit (pencil) icon button for this record.
        // The button uses wire:click="editHardware({id})".
        ->click('[wire\\:click*="editHardware('.$this->hardware->id.')"]')
        ->wait(1)
        // The edit modal should appear.
        ->assertSee('ویرایش سخت افزار')
        ->assertSee('ذخیره')
        ->assertSee('لغو')
        ->assertNoJavaScriptErrors();
});

it('modal shows pre-filled data', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        ->click('[wire\\:click*="editHardware('.$this->hardware->id.')"]')
        ->wait(1)
        // Modal title.
        ->assertSee('ویرایش سخت افزار')
        // Field labels visible in the modal.
        ->assertSee('نام دستگاه')
        ->assertSee('نوع')
        ->assertSee('سیستم عامل')
        ->assertSee('CPU')
        // The pc_name input should have the original value pre-filled.
        ->assertValue('[wire\\:model="pc_name"]', 'PC-Edit-Orig')
        ->assertNoJavaScriptErrors();
});

it('can change fields and submit updates record', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        ->click('[wire\\:click*="editHardware('.$this->hardware->id.')"]')
        ->wait(1)
        // Clear pc_name and type a new value.
        ->clear('[wire\\:model="pc_name"]')
        ->type('[wire\\:model="pc_name"]', 'PC-Edit-New')
        // Change the type field.
        ->clear('[wire\\:model="type"]')
        ->type('[wire\\:model="type"]', 'laptop')
        // Click save.
        ->click('ذخیره')
        ->wait(2) // Wait for Livewire update + modal close.
        // The updated record should appear in the table.
        ->assertSee('PC-Edit-New')
        // The original name should no longer be visible.
        ->assertDontSee('PC-Edit-Orig')
        ->assertNoJavaScriptErrors();
});
