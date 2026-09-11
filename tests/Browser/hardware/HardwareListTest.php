<?php

/*
|--------------------------------------------------------------------------
| Hardware List Browser Tests
|--------------------------------------------------------------------------
|
| Tests the hardware index page: page load, table display, search,
| type filter, column sorting, pagination, and audit trail modal.
|
| Run: pest tests/Browser/hardware/HardwareListTest.php --browser
|
*/

use App\Models\Hardware;

beforeEach(function () {
    Hardware::query()->forceDelete();

    [$this->user, $this->unit, $this->nCode] = createHardwareAdmin();

    // Create 25 hardware records (2 pages at default perPage=20).
    $this->hardwareRecords = [];
    for ($i = 1; $i <= 25; $i++) {
        $this->hardwareRecords[] = createSampleHardware($this->unit, $this->nCode, [
            'pc_name' => "PC-{$i}-TEST",
            'type' => $i <= 10 ? 'laptop' : 'desktop',
            'os' => $i <= 5 ? 'Windows 11' : 'Windows 10',
            'cpu' => $i % 2 === 0 ? 'Intel i7' : 'Intel i5',
        ]);
    }
});

afterEach(function () {
    Hardware::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('loads the hardware page and shows Persian header', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        ->assertSee('شناسنامه سخت افزار')
        ->assertSee('افزودن')
        ->assertSee('جستجو در تمام فیلدها...')
        ->assertNoJavaScriptErrors();
});

it('displays the hardware table with columns', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Table column headers (Persian labels).
        ->assertSee('نام دستگاه')
        ->assertSee('صاحب')
        ->assertSee('وضعیت')
        ->assertSee('عملیات')
        // At least one record from our seed data should be visible.
        ->assertSee('PC-1-TEST')
        ->assertNoJavaScriptErrors();
});

it('search filters hardware', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Type a unique search term into the search input (CSS selector for wire:model.live.debounce).
        ->type('[wire\\:model\\.live\\.debounce="search"]', 'PC-7-TEST')
        ->wait(1) // Debounce + Livewire round-trip.
        // The matching record should be visible.
        ->assertSee('PC-7-TEST')
        // Other records should be filtered out.
        ->assertDontSee('PC-1-TEST')
        ->assertNoJavaScriptErrors();
});

it('type filter works via quick preset', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Click the "لپ‌تاپ‌ها" quick-preset button (filters by type=laptop).
        ->click('لپ‌تاپ‌ها')
        ->wait(1)
        // Laptop records (1-10) should appear.
        ->assertSee('PC-1-TEST')
        ->assertSee('PC-10-TEST')
        // Desktop records (11-25) should be filtered out.
        ->assertDontSee('PC-15-TEST')
        ->assertDontSee('PC-25-TEST')
        ->assertNoJavaScriptErrors();
});

it('sort by column works', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Click the "نام دستگاه" column header to sort.
        ->click('نام دستگاه')
        ->wait(1)
        // After sorting ascending, PC-1-TEST should be visible.
        ->assertSee('PC-1-TEST')
        // Click again to toggle sort direction.
        ->click('نام دستگاه')
        ->wait(1)
        // After sorting descending, PC-25-TEST should be visible.
        ->assertSee('PC-25-TEST')
        ->assertNoJavaScriptErrors();
});

it('pagination works', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Default perPage=20, so page 1 shows records 1-20.
        ->assertSee('PC-1-TEST')
        ->assertDontSee('PC-25-TEST')
        // Navigate to page 2 by clicking the "2" pagination link.
        ->click('2')
        ->wait(1)
        // Page 2 should show the remaining records.
        ->assertSee('PC-25-TEST')
        ->assertNoJavaScriptErrors();
});

it('expand row shows audit trail', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Click the history (clock) icon button for the first hardware record.
        // The button uses wire:click="loadHistory({id})" — select by attribute.
        ->click('[wire\\:click*="loadHistory('.$this->hardwareRecords[0]->id.')"]')
        ->wait(1)
        // The audit modal should appear with the modal title.
        ->assertSee('تاریخچه تغییرات')
        // Since we just created records, there should be a "created" (ایجاد) audit entry.
        ->assertSee('ایجاد')
        ->assertNoJavaScriptErrors();
});
