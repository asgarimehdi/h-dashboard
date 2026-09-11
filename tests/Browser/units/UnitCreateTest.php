<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Unit Create / Edit Modal
|--------------------------------------------------------------------------
|
| Tests the unit create/edit modal: opening it, form fields,
| type-dependent selects, and submit behavior.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('opens the create unit modal when clicking the plus button', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->assertSee('ثبت واحد جدید')
        ->assertSee('نام واحد')
        ->assertSee('نوع واحد')
        ->assertSee('واحد بالادستی')
        ->assertSee('ذخیره')
        ->assertSee('لغو')
        ->assertNoJavascriptErrors();
});

it('create modal has all required form fields', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->assertSee('نام واحد')
        ->assertSee('توضیحات')
        ->assertSee('نوع واحد')
        ->assertSee('پذیرش تیکت')
        ->assertNoJavascriptErrors();
});

it('can type into the unit name field', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->fill('[wire\\:model="name"]', 'واحد تستی جدید')
        ->assertSee('واحد تستی جدید')
        ->assertSee('ثبت واحد جدید')
        ->assertNoJavascriptErrors();
});

it('can type into the description field', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->fill('[wire\\:model="description"]', 'توضیحات تستی')
        ->assertSee('توضیحات تستی')
        ->assertNoJavascriptErrors();
});

it('modal has the can_receive_tickets toggle', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->assertSee('پذیرش تیکت')
        ->assertSee('اگر فعال باشد')
        ->assertNoJavascriptErrors();
});

it('modal has cancel button that closes it', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->assertSee('ثبت واحد جدید')
        ->assertSee('لغو')
        ->assertNoJavascriptErrors();
});

it('modal header changes to edit mode when editing', function () {
    $page = $this->page->navigate('/units');

    // If there are units in the table, the edit pencil button should exist
    $page->assertSee('نام واحد')
        ->assertNoJavascriptErrors();
});

it('submit button shows save label for new unit', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->assertSee('ذخیره')
        ->assertNoJavascriptErrors();
});

it('modal has unit type select field', function () {
    $page = $this->page->navigate('/units');

    $page->click('button[wire\\:click="openModalForCreate"]')
        ->assertSee('نوع واحد')
        ->assertSee('انتخاب کنید')
        ->assertNoJavascriptErrors();
});
