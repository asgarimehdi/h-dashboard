<?php

/*
|--------------------------------------------------------------------------
| Hardware Create Browser Tests
|--------------------------------------------------------------------------
|
| Tests creating new hardware: inline form appearance, form fields,
| validation errors on empty submission, and person search autocomplete.
|
| Run: pest tests/Browser/hardware/HardwareCreateTest.php --browser
|
*/

use App\Models\Hardware;
use App\Models\Person;

beforeEach(function () {
    Hardware::query()->forceDelete();

    [$this->user, $this->unit, $this->nCode] = createHardwareAdmin();
    $this->person = Person::where('n_code', $this->nCode)->first();
});

afterEach(function () {
    Hardware::query()->forceDelete();
    if (isset($this->unit)) {
        $this->unit->delete();
    }
});

// ==================== Tests ====================

it('create button opens inline form', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        // Click the "افزودن" (Add) button to open the inline create form.
        ->click('افزودن')
        ->wait(1)
        // The form action buttons should appear.
        ->assertSee('ذخیره')
        ->assertSee('لغو')
        ->assertNoJavaScriptErrors();
});

it('form shows person search, pc_name, and other fields', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        ->click('افزودن')
        ->wait(1)
        // All key form fields should be visible (labels in Persian).
        ->assertSee('کد ملی / نام پرسنل')
        ->assertSee('نام دستگاه')
        ->assertSee('نوع')
        ->assertSee('سیستم عامل')
        ->assertSee('IP محلی')
        ->assertSee('CPU')
        ->assertSee('RAM')
        ->assertSee('HDD/SSD')
        ->assertSee('فعال')      // shutdown checkbox
        ->assertSee('علامت')    // mark checkbox
        ->assertSee('توضیحات')  // comments
        ->assertNoJavaScriptErrors();
});

it('submitting empty form shows validation errors', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        ->click('افزودن')
        ->wait(1)
        // Click save without filling any required fields.
        ->click('ذخیره')
        ->wait(1)
        // Validation errors should appear — Livewire emits inline error messages.
        ->assertSee('فیلد')
        ->assertNoJavaScriptErrors();
});

it('person search autocomplete works and form submits', function () {
    $page = loginThroughBrowser($this->nCode);

    $page->navigate('/hardware')
        ->waitForText('شناسنامه سخت افزار')
        ->click('افزودن')
        ->wait(1)
        // Type the person's first name into the n_code / person search field.
        ->type('[wire\\:model\\.live="n_code"]', $this->person->f_name)
        ->wait(2) // Wait for Livewire autocomplete debounce.
        // The autocomplete dropdown should show the person's full name.
        ->assertSee('علی رضايي')
        // Click on the autocomplete result to select the person.
        ->click('علی رضايي ('.$this->nCode.')')
        ->wait(1)
        // The person should now be selected (shown below the input).
        ->assertSee('علی رضايي')
        // Fill in the required pc_name field.
        ->type('[wire\\:model="pc_name"]', 'PC-New-Browser')
        // Submit the form.
        ->click('ذخیره')
        ->wait(2) // Wait for Livewire round-trip.
        // The new record should appear in the table.
        ->assertSee('PC-New-Browser')
        ->assertNoJavaScriptErrors();
});
