<?php

/*
|--------------------------------------------------------------------------
| Browser Test
|--------------------------------------------------------------------------
|
| Uses Pest v4's browser testing (Playwright-based) to test Livewire
| components in a real browser. Slower than unit tests but provides
| end-to-end confidence.
|
| Run: composer test:browser
| Or:  php artisan test --testsuite=Browser
|
*/

it('loads the login page and shows Persian text', function () {
    $page = visit('/login');

    $page->assertSee('ورود به حساب کاربری')
        ->assertSee('کد ملی')
        ->assertSee('رمز عبور')
        ->assertNoJavascriptErrors();
});
