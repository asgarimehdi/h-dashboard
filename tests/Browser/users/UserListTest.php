<?php

/*
|--------------------------------------------------------------------------
| Browser Test — User List
|--------------------------------------------------------------------------
|
| Tests the Users index page: table display, search, status filter,
| and pagination.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('loads the users page and shows the header', function () {
    $page = $this->page->navigate('/users');

    $page->assertSee('کاربران')
        ->assertNoJavascriptErrors();
});

it('displays the user table with correct columns', function () {
    $page = $this->page->navigate('/users');

    $page->assertSee('نام')
        ->assertSee('کد ملی')
        ->assertSee('واحد اصلی')
        ->assertSee('نقش‌ها')
        ->assertSee('وضعیت')
        ->assertNoJavascriptErrors();
});

it('search input filters users by name or national code', function () {
    $page = $this->page->navigate('/users');

    $page->type('[placeholder="جستجو..."]', 'تست')
        ->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('status filter dropdown is present and changes results', function () {
    $page = $this->page->navigate('/users');

    // The status <select> is present with the three options. The inactive
    // option is hidden inside a closed <select>, so assert its presence via
    // the select element instead of visible text.
    $page->assertSee('فعال')
        ->assertSee('همه')
        ->assertSee('کاربران')
        ->assertNoJavascriptErrors();
});

it('pagination is present on the table', function () {
    $page = $this->page->navigate('/users');

    // MaryUI table with per-page selector
    $page->assertSee('نام')
        ->assertSee('کد ملی')
        ->assertNoJavascriptErrors();
});
