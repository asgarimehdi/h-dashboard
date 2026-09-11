<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Dashboard
|--------------------------------------------------------------------------
|
| Tests the main dashboard page (/dashboard) loads correctly, displays
| stat cards, renders Highcharts, and shows the recent activities section.
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
| Setup — seed permissions, create a user with all permissions, store
| credentials so browser tests can log in.
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

it('loads the dashboard page and shows the header', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('داشبورد مدیریت اطلاعات سلامت')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();
});

it('shows stat cards for users, persons, units, and roles', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('کاربران')
        ->assertSee('پرسنل')
        ->assertSee('واحدها')
        ->assertSee('نقش‌ها')
        ->assertNoJavascriptErrors();
});

it('shows ticket stat cards', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('کل تیکت‌ها')
        ->assertSee('تیکت‌های باز')
        ->assertSee('تیکت‌های تکمیل شده')
        ->assertNoJavascriptErrors();
});

it('shows chart containers for ticket trend and status', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    // Chart section headers
    $page->assertSee('روند ایجاد تیکت‌ها')
        ->assertSee('وضعیت تیکت‌ها')
        ->assertNoJavascriptErrors();
});

it('shows the today summary section', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('خلاصه امروز')
        ->assertSee('تیکت جدید')
        ->assertSee('وظیفه جدید')
        ->assertSee('فعالیت کل')
        ->assertNoJavascriptErrors();
});

it('shows recent activities section', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('آخرین فعالیت‌ها')
        ->assertNoJavascriptErrors();
});

it('shows detailed ticket stats section', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('آمار تفصیلی تیکت‌ها')
        ->assertSee('فوری')
        ->assertSee('عادی')
        ->assertSee('سررسید گذشته')
        ->assertNoJavascriptErrors();
});
