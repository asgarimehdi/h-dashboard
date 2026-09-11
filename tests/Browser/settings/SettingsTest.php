<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Settings
|--------------------------------------------------------------------------
|
| Tests the settings page (/settings) loads correctly and shows
| user preference controls (notifications, dashboard display, compact mode).
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

it('loads the settings page and shows the header', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/settings')
        ->wait(2)
        ->assertSee('تنظیمات')
        ->assertPathIs('/settings')
        ->assertNoJavascriptErrors();
});

it('shows notification preferences', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/settings')
        ->wait(2)
        ->assertSee('اعلان‌ها')
        ->assertSee('اعلان ایمیلی')
        ->assertSee('اعلان مرورگر')
        ->assertNoJavascriptErrors();
});

it('shows dashboard display preferences', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/settings')
        ->wait(2)
        ->assertSee('نمای داشبورد')
        ->assertSee('بروزرسانی خودکار')
        ->assertSee('حالت فشرده')
        ->assertNoJavascriptErrors();
});

it('shows the save button', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/settings')
        ->wait(2)
        ->assertSee('ذخیره تنظیمات')
        ->assertNoJavascriptErrors();
});

it('auto-refresh select has expected options', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/settings')
        ->wait(2)
        ->assertSee('غیرفعال')
        ->assertSee('هر ۱۵ ثانیه')
        ->assertSee('هر ۳۰ ثانیه')
        ->assertSee('هر ۱ دقیقه')
        ->assertNoJavascriptErrors();
});
