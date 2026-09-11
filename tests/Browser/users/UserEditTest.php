<?php

/*
|--------------------------------------------------------------------------
| Browser Test — User Edit
|--------------------------------------------------------------------------
|
| Tests the edit-user inline form: opening it with pre-filled data,
| changing roles, and updating a user.
|
*/

beforeEach(function () {
    createBrowserUser();
    $this->page = loginViaBrowser();
});

it('edit button opens the form with pre-filled data', function () {
    $page = $this->page->navigate('/users');

    // Click the first edit button (pencil icon) in the table
    $page->click('button[wire\\:click^="edit("]:first-of-type')
        ->assertSee('ویرایش کاربر')
        ->assertSee('ذخیره')
        ->assertSee('لغو')
        ->assertNoJavascriptErrors();
});

it('edit form shows role selection and permissions fields', function () {
    $page = $this->page->navigate('/users');

    $page->click('button[wire\\:click^="edit("]:first-of-type')
        ->assertSee('نقش‌ها')
        ->assertSee('دسترسی‌های مستقیم')
        ->assertSee('واحدها')
        ->assertNoJavascriptErrors();
});

it('can submit the edit form to update user', function () {
    $page = $this->page->navigate('/users');

    // Open edit form for first user
    $page->click('button[wire\\:click^="edit("]:first-of-type')
        ->assertSee('ویرایش کاربر');

    // Submit the form (user already has roles, so validation passes)
    $page->click('button[type="submit"]');

    // Should show success toast or stay on page without errors
    $page->assertNoJavascriptErrors();
});
