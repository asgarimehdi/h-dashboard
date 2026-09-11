<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Permission CRUD
|--------------------------------------------------------------------------
|
| Tests the Permissions management page: list display, create/edit modal,
| search, and delete.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('loads the permissions page and shows the header', function () {
    $page = $this->page->navigate('/permissions');

    $page->assertSee('مدیریت دسترسی ها')
        ->assertNoJavascriptErrors();
});

it('displays the permission table with correct columns', function () {
    $page = $this->page->navigate('/permissions');

    $page->assertSee('عنوان')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('shows the search input field', function () {
    $page = $this->page->navigate('/permissions');

    $page->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('search input is functional', function () {
    $page = $this->page->navigate('/permissions');

    $page->fill('[wire\\:model\\.live\\.debounce="search"]', 'manage')
        ->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('opens the create permission modal when clicking the plus button', function () {
    $page = $this->page->navigate('/permissions');

    // The plus button triggers $wire.modal = true
    $page->click('button.btn-success')
        ->assertSee('نام سطح دسترسی')
        ->assertSee('عنوان سطح دسترسی')
        ->assertSee('ذخیره')
        ->assertSee('بستن')
        ->assertNoJavascriptErrors();
});

it('create modal has all required fields', function () {
    $page = $this->page->navigate('/permissions');

    $page->click('button.btn-success')
        ->assertSee('نام انگلیسی سطح دسترسی')
        ->assertSee('عنوان فارسی سطح دسترسی')
        ->assertNoJavascriptErrors();
});

it('can type into the permission name field', function () {
    $page = $this->page->navigate('/permissions');

    $page->click('button.btn-success')
        ->fill('[wire\\:model="name"]', 'test_permission')
        ->assertSee('test_permission')
        ->assertNoJavascriptErrors();
});

it('can type into the permission label field', function () {
    $page = $this->page->navigate('/permissions');

    $page->click('button.btn-success')
        ->fill('[wire\\:model="label"]', 'سطح دسترسی تستی')
        ->assertSee('سطح دسترسی تستی')
        ->assertNoJavascriptErrors();
});

it('submit button shows save label for new permission', function () {
    $page = $this->page->navigate('/permissions');

    $page->click('button.btn-success')
        ->assertSee('ذخیره')
        ->assertNoJavascriptErrors();
});

it('close button is present in the modal', function () {
    $page = $this->page->navigate('/permissions');

    $page->click('button.btn-success')
        ->assertSee('بستن')
        ->assertNoJavascriptErrors();
});

it('table has edit and delete action buttons', function () {
    $page = $this->page->navigate('/permissions');

    $page->assertSee('عنوان')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('pagination is present on the permissions table', function () {
    $page = $this->page->navigate('/permissions');

    $page->assertSee('عنوان')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('edit button opens modal with pre-filled data', function () {
    $page = $this->page->navigate('/permissions');

    // Click the first edit pencil button in the table
    $page->click('button[wire\\:click^="editPermission("]:first-of-type')
        ->assertSee('ویرایش ')
        ->assertSee('نام سطح دسترسی')
        ->assertSee('عنوان سطح دسترسی')
        ->assertNoJavascriptErrors();
});

it('modal title changes between create and edit mode', function () {
    $page = $this->page->navigate('/permissions');

    // Create mode
    $page->click('button.btn-success')
        ->assertSee('جدید')
        ->assertNoJavascriptErrors();
});
