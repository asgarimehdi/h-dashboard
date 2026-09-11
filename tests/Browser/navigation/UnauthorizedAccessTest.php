<?php

/*
|--------------------------------------------------------------------------
| Unauthorized Access Browser Test
|--------------------------------------------------------------------------
|
| Uses Pest v4's browser testing (Playwright-based) to verify that all
| protected routes redirect unauthenticated users to the /login page.
| No login is performed — each visit starts a fresh, unauthenticated browser.
|
| Run: composer test:browser
| Or:  php artisan test --testsuite=Browser
|
*/

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('redirects unauthenticated user to login from /dashboard', function (): void {
    $page = visit('/dashboard');

    $page->assertPathIs('/login')
        ->assertSee('ورود به حساب کاربری')
        ->assertNoJavascriptErrors();
});

it('redirects unauthenticated user to login from /users', function (): void {
    $page = visit('/users');

    $page->assertPathIs('/login')
        ->assertSee('ورود به حساب کاربری')
        ->assertNoJavascriptErrors();
});

it('redirects unauthenticated user to login from /hardware', function (): void {
    $page = visit('/hardware');

    $page->assertPathIs('/login')
        ->assertSee('ورود به حساب کاربری')
        ->assertNoJavascriptErrors();
});

it('redirects unauthenticated user to login from /tickets/inbox', function (): void {
    $page = visit('/tickets/inbox');

    $page->assertPathIs('/login')
        ->assertSee('ورود به حساب کاربری')
        ->assertNoJavascriptErrors();
});

it('redirects unauthenticated user to login from /todo', function (): void {
    $page = visit('/todo');

    $page->assertPathIs('/login')
        ->assertSee('ورود به حساب کاربری')
        ->assertNoJavascriptErrors();
});

it('redirects unauthenticated user to login from /units', function (): void {
    $page = visit('/units');

    $page->assertPathIs('/login')
        ->assertSee('ورود به حساب کاربری')
        ->assertNoJavascriptErrors();
});
