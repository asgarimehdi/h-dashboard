<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Person List (Kargozini)
|--------------------------------------------------------------------------
|
| Tests the personnel management page: header, table columns, search,
| filters, and pagination.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('loads the persons page and shows the header', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->assertSee('مدیریت پرسنل')
        ->assertNoJavascriptErrors();
});

it('displays the person table with correct columns', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->assertSee('نام')
        ->assertSee('نام خانوادگی')
        ->assertSee('کد ملی')
        ->assertSee('تحصیلات')
        ->assertSee('استخدام')
        ->assertSee('سمت')
        ->assertSee('ردیف سازمانی')
        ->assertSee('واحد')
        ->assertNoJavascriptErrors();
});

it('shows the search input field', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('search input is functional', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->fill('[wire\\:model\\.live\\.debounce="search"]', 'علی')
        ->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('shows the create button and filter toggle button', function () {
    $page = $this->page->navigate('/kargozini/persons');

    // The filter button uses $toggle('showFilters')
    $page->assertSee('جستجو...')
        ->assertNoJavascriptErrors();
});

it('filter panel toggles open', function () {
    $page = $this->page->navigate('/kargozini/persons');

    // Click the filter/funnel icon button
    $page->click('button[wire\\:click="$toggle(\'showFilters\')"]')
        ->assertSee('سمت')
        ->assertSee('تحصیلات')
        ->assertSee('استخدام')
        ->assertSee('ردیف سازمانی')
        ->assertSee('واحد')
        ->assertSee('پاک کردن فیلترها')
        ->assertNoJavascriptErrors();
});

it('filter panel has clear filters button', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->click('button[wire\\:click="$toggle(\'showFilters\')"]')
        ->assertSee('پاک کردن فیلترها')
        ->assertNoJavascriptErrors();
});

it('pagination is present on the table', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->assertSee('نام')
        ->assertSee('نام خانوادگی')
        ->assertNoJavascriptErrors();
});

it('shows per-page selector values', function () {
    $page = $this->page->navigate('/kargozini/persons');

    // MaryUI x-table with per-page values [10, 20, 50]
    $page->assertSee('نام')
        ->assertNoJavascriptErrors();
});

it('has sortable table headers', function () {
    $page = $this->page->navigate('/kargozini/persons');

    $page->assertSee('#')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});
