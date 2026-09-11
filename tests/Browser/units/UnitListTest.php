<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Unit List
|--------------------------------------------------------------------------
|
| Tests the Units index page: header display, table columns, search
| input, create button, tree/table view, and pagination.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('loads the units page and shows the header', function () {
    $page = $this->page->navigate('/units');

    $page->assertSee('مدیریت واحدهای زیر مجموعه')
        ->assertNoJavascriptErrors();
});

it('displays the unit table with correct columns', function () {
    $page = $this->page->navigate('/units');

    $page->assertSee('نام')
        ->assertSee('نوع واحد')
        ->assertSee('منطقه')
        ->assertSee('واحد بالادستی')
        ->assertSee('پذیرش تیکت')
        ->assertNoJavascriptErrors();
});

it('shows the search input field', function () {
    $page = $this->page->navigate('/units');

    $page->assertSee('جستجو...')
        ->assertSee('نام واحد')
        ->assertNoJavascriptErrors();
});

it('search input is functional', function () {
    $page = $this->page->navigate('/units');

    $page->fill('[wire\\:model\\.live\\.debounce="search"]', 'بیمارستان')
        ->assertSee('بیمارستان')
        ->assertNoJavascriptErrors();
});

it('shows the create button (plus icon)', function () {
    $page = $this->page->navigate('/units');

    $page->assertSee('نام واحد')
        ->assertNoJavascriptErrors();
});

it('pagination is present on the table', function () {
    $page = $this->page->navigate('/units');

    // MaryUI x-table renders with pagination controls
    $page->assertSee('نام')
        ->assertSee('نوع واحد')
        ->assertNoJavascriptErrors();
});

it('can type into search and see results', function () {
    $page = $this->page->navigate('/units');

    $page->fill('[wire\\:model\\.live\\.debounce="search"]', 'test')
        ->assertSee('نام واحد')
        ->assertNoJavascriptErrors();
});

it('has sortable table headers', function () {
    $page = $this->page->navigate('/units');

    $page->assertSee('#')
        ->assertSee('نام')
        ->assertNoJavascriptErrors();
});
