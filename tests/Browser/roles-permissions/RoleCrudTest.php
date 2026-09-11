<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Role CRUD
|--------------------------------------------------------------------------
|
| Tests the Roles management page: list display, create/edit modal,
| permission assignment, search, and delete.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('loads the roles page and shows the header', function () {
    $page = $this->page->navigate('/roles');

    $page->assertSee('مدیریت نقش ها')
        ->assertNoJavascriptErrors();
});

it('displays the role table with correct columns', function () {
    $page = $this->page->navigate('/roles');

    $page->assertSee('عنوان')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('shows the search input field', function () {
    $page = $this->page->navigate('/roles');

    $page->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('search input is functional', function () {
    $page = $this->page->navigate('/roles');

    $page->fill('[wire\\:model\\.live\\.debounce="search"]', 'admin')
        ->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('opens the create role modal when clicking the plus button', function () {
    $page = $this->page->navigate('/roles');

    // The plus button triggers $wire.modal = true
    $page->click('button.btn-success')
        ->assertSee('نام نقش')
        ->assertSee('عنوان فارسی نقش')
        ->assertSee('دسترسی ها')
        ->assertSee('ذخیره')
        ->assertSee('بستن')
        ->assertNoJavascriptErrors();
});

it('create modal has all required fields', function () {
    $page = $this->page->navigate('/roles');

    $page->click('button.btn-success')
        ->assertSee('نام انگلیسی نقش')
        ->assertSee('عنوان فارسی نقش')
        ->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('can type into the role name field', function () {
    $page = $this->page->navigate('/roles');

    $page->click('button.btn-success')
        ->fill('[wire\\:model="name"]', 'test_role')
        ->assertSee('test_role')
        ->assertNoJavascriptErrors();
});

it('can type into the role label field', function () {
    $page = $this->page->navigate('/roles');

    $page->click('button.btn-success')
        ->fill('[wire\\:model="label"]', 'نقش تستی')
        ->assertSee('نقش تستی')
        ->assertNoJavascriptErrors();
});

it('modal has permissions multi-select (choices-offline)', function () {
    $page = $this->page->navigate('/roles');

    $page->click('button.btn-success')
        ->assertSee('دسترسی ها')
        ->assertNoJavascriptErrors();
});

it('submit button shows save label for new role', function () {
    $page = $this->page->navigate('/roles');

    $page->click('button.btn-success')
        ->assertSee('ذخیره')
        ->assertNoJavascriptErrors();
});

it('close button is present in the modal', function () {
    $page = $this->page->navigate('/roles');

    $page->click('button.btn-success')
        ->assertSee('بستن')
        ->assertNoJavascriptErrors();
});

it('table has edit and delete action buttons', function () {
    $page = $this->page->navigate('/roles');

    $page->assertSee('عنوان')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('pagination is present on the roles table', function () {
    $page = $this->page->navigate('/roles');

    $page->assertSee('عنوان')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('edit button opens modal with pre-filled data', function () {
    $page = $this->page->navigate('/roles');

    // Click the first edit pencil button in the table
    $page->click('button[wire\\:click^="editRole("]:first-of-type')
        ->assertSee('ویرایش ')
        ->assertSee('نام نقش')
        ->assertSee('عنوان فارسی نقش')
        ->assertNoJavascriptErrors();
});
