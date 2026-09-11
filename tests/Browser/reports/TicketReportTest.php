<?php

/*
|--------------------------------------------------------------------------
| Browser Test — Ticket Reports (Advanced)
|--------------------------------------------------------------------------
|
| Tests the ticket reports page (/reports/tickets) loads correctly,
| date filters are present, chart containers render, and report
| section headers appear in Persian (RTL).
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

it('loads the ticket reports page and shows the header', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('گزارش تیکت‌ها')
        ->assertPathIs('/reports/tickets')
        ->assertNoJavascriptErrors();
});

it('shows date filter inputs with Jalali placeholders', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('از تاریخ')
        ->assertSee('تا تاریخ')
        ->assertNoJavascriptErrors();
});

it('shows report type filter options', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('نوع گزارش')
        ->assertSee('تیکت‌ها')
        ->assertSee('وضعیت')
        ->assertNoJavascriptErrors();
});

it('shows unit filter selects', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('واحد اصلی')
        ->assertSee('واحد فرعی')
        ->assertNoJavascriptErrors();
});

it('shows stat cards with report totals', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('کل')
        ->assertNoJavascriptErrors();
});

it('shows chart containers for trend and unit distribution', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('روند روزانه')
        ->assertSee('توزیع بر اساس واحد')
        ->assertNoJavascriptErrors();
});

it('status filter contains expected options', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->visit('/reports/tickets')
        ->wait(2)
        ->assertSee('ایجاد شده')
        ->assertSee('ارجاع شده')
        ->assertSee('تکمیل شده')
        ->assertNoJavascriptErrors();
});
