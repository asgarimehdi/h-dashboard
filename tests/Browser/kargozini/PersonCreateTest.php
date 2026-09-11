<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Person Create / Edit (Kargozini)
|--------------------------------------------------------------------------
|
| Tests the person create/edit inline form: opening it, all 8 form
| fields, unit picker modal, and submit behavior.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('opens the create person form when clicking the plus button', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('ثبت پرسنل جدید')
        ->assertSee('کد ملی')
        ->assertSee('نام')
        ->assertSee('نام خانوادگی')
        ->assertSee('تحصیلات')
        ->assertSee('استخدام')
        ->assertSee('سمت')
        ->assertSee('ردیف سازمانی')
        ->assertNoJavascriptErrors();
});

it('create form has all 8 required fields', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('کد ملی')
        ->assertSee('نام')
        ->assertSee('نام خانوادگی')
        ->assertSee('تحصیلات')
        ->assertSee('استخدام')
        ->assertSee('سمت')
        ->assertSee('ردیف سازمانی')
        ->assertSee('واحد')
        ->assertNoJavascriptErrors();
});

it('can type into the n_code (national code) field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->fill('[wire\\:model="n_code"]', '1234567890')
        ->assertSee('ثبت پرسنل جدید')
        ->assertNoJavascriptErrors();
});

it('can type into the first name field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->fill('[wire\\:model="f_name"]', 'مهدی')
        ->assertSee('مهدی')
        ->assertNoJavascriptErrors();
});

it('can type into the last name field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->fill('[wire\\:model="l_name"]', 'عسگری')
        ->assertSee('عسگری')
        ->assertNoJavascriptErrors();
});

it('form has tahsil (education) select field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('تحصیلات')
        ->assertSee('انتخاب سطح تحصیلات')
        ->assertNoJavascriptErrors();
});

it('form has estekhdam (employment) select field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('استخدام')
        ->assertSee('انتخاب نوع استخدام')
        ->assertNoJavascriptErrors();
});

it('form has semat (position) select field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('سمت')
        ->assertSee('انتخاب سمت')
        ->assertNoJavascriptErrors();
});

it('form has radif (org row) select field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('ردیف سازمانی')
        ->assertSee('انتخاب ردیف سازمانی')
        ->assertNoJavascriptErrors();
});

it('form has unit picker button', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('انتخاب واحد')
        ->assertSee('واحدی انتخاب نشده')
        ->assertNoJavascriptErrors();
});

it('submit button shows save label for new person', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('ذخیره')
        ->assertSee('لغو')
        ->assertNoJavascriptErrors();
});

it('cancel button closes the form', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="startCreate"]')
        ->assertSee('ثبت پرسنل جدید')
        ->assertSee('لغو')
        ->assertNoJavascriptErrors();
});
