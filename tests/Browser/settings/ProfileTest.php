<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Profile
|--------------------------------------------------------------------------
|
| Tests the profile page (/profile) loads correctly, shows user info,
| stat cards, and tabbed sections (tickets, todos, activities).
| All text is Persian (RTL).
|
| Run: composer test:browser
| Or:  php artisan test --testsuite=Browser
|
*/

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Setup
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $allPermissions = Permission::pluck('name')->toArray();
    $this->user->syncPermissions($allPermissions);

    $this->nCode = $this->user->n_code;
    $this->password = 'password';
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('loads the profile page and shows the header', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/profile')
        ->wait(2)
        ->assertSee('پروفایل من')
        ->assertPathIs('/profile')
        ->assertNoJavascriptErrors();
});

it('shows user name and national code', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/profile')
        ->wait(2)
        ->assertSee($this->user->name)
        ->assertSee('کد ملی')
        ->assertSee($this->nCode)
        ->assertNoJavascriptErrors();
});

it('shows the change password button', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/profile')
        ->wait(2)
        ->assertSee('تغییر رمز عبور')
        ->assertNoJavascriptErrors();
});

it('shows stat cards for tickets and todos', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/profile')
        ->wait(2)
        ->assertSee('کل تیکت‌ها')
        ->assertSee('تکمیل شده')
        ->assertSee('در انتظار')
        ->assertSee('وظایف')
        ->assertNoJavascriptErrors();
});

it('shows tab navigation for tickets, todos, and activities', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/profile')
        ->wait(2)
        ->assertSee('تیکت‌ها')
        ->assertSee('وظایف')
        ->assertSee('فعالیت‌ها')
        ->assertNoJavascriptErrors();
});

it('shows the tickets tab content by default', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/profile')
        ->wait(2)
        ->assertSee('تیکت‌های من')
        ->assertNoJavascriptErrors();
});
