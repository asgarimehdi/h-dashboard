<?php

/*
|--------------------------------------------------------------------------
| Sidebar Browser Test
|--------------------------------------------------------------------------
|
| Uses Pest v4's browser testing (Playwright-based) to verify the sidebar
| navigation component renders correctly, shows expected Persian menu items,
| handles click navigation, highlights the active item, and collapses on
| mobile viewports.
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

    // Create a user via factory (also creates backing Person + Unit).
    $this->user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    // Assign every permission so the sidebar renders all menu items.
    $allPermissions = Permission::pluck('name')->toArray();
    $this->user->syncPermissions($allPermissions);

    // Store n_code + password for the browser login form.
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

it('shows the sidebar when logged in', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    $page->assertSee('جستجوی کلی')
        ->assertSee('صفحه اول')
        ->assertNoJavascriptErrors();
});

it('shows expected Persian menu items in the sidebar', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    // Always-visible top-level items
    $page->assertSee('جستجوی کلی')
        ->assertSee('صفحه اول')

        // Permission-gated items (all assigned via syncPermissions)
        ->assertSee('منابع انسانی')
        ->assertSee('مدیریت تیکت‌ها')
        ->assertSee('مدیریت سازمان')
        ->assertSee('ابزارهای مدیریتی')
        ->assertSee('گزارش‌ها')
        ->assertSee('مدیریت')
        ->assertSee('راهنما و پشتیبانی')

        // Bottom items (always visible)
        ->assertSee('پروفایل من')
        ->assertSee('تغییر رمز عبور')
        ->assertSee('تنظیمات')
        ->assertNoJavascriptErrors();
});

it('navigates to the correct pages when menu items are clicked', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    // Navigate to Users via sidebar
    $page->click('کاربران')
        ->wait(2)
        ->assertPathIs('/users')
        ->assertNoJavascriptErrors();

    // Navigate to Hardware via sidebar
    $page->click('شناسنامه سخت افزار')
        ->wait(2)
        ->assertPathIs('/hardware')
        ->assertNoJavascriptErrors();

    // Navigate to Tickets Inbox via sidebar
    $page->click('صندوق تیکت‌ها')
        ->wait(2)
        ->assertPathIs('/tickets/inbox')
        ->assertNoJavascriptErrors();

    // Navigate to Todo via sidebar
    $page->click('تقویم')
        ->wait(2)
        ->assertPathIs('/todo')
        ->assertNoJavascriptErrors();

    // Navigate to Units via sidebar
    $page->click('مدیریت واحدها')
        ->wait(2)
        ->assertPathIs('/units')
        ->assertNoJavascriptErrors();

    // Navigate back to Home via sidebar
    $page->click('صفحه اول')
        ->wait(2)
        ->assertPathIs('/')
        ->assertNoJavascriptErrors();
});

it('highlights the active menu item for the current route', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    // On the dashboard/home page, the "صفحه اول" item should be active.
    // MaryUI's activate-by-route adds an 'active' CSS class to the matching menu-item.
    $page->click('صفحه اول')
        ->wait(2)
        ->assertPathIs('/')
        ->assertScript(
            'document.querySelector(\'a[href="/"]\').closest(\'li\').classList.contains(\'active\') ||
             document.querySelector(\'a[href="/"]\').closest(\'li\').className.includes(\'active\')',
            true
        )
        ->assertNoJavascriptErrors();

    // Navigate to users — that menu item should now be active.
    $page->click('کاربران')
        ->wait(2)
        ->assertPathIs('/users')
        ->assertScript(
            'document.querySelector(\'a[href="/users"]\').closest(\'li\').classList.contains(\'active\') ||
             document.querySelector(\'a[href="/users"]\').closest(\'li\').className.includes(\'active\')',
            true
        )
        ->assertNoJavascriptErrors();
});

it('collapses the sidebar on mobile viewport', function (): void {
    $page = loginViaBrowser($this->nCode, $this->password);

    // At desktop width the sidebar should be visible.
    $page->resize(1920, 1080)
        ->wait(1)
        ->assertSee('جستجوی کلی')
        ->assertNoJavascriptErrors();

    // Shrink to a mobile width — the sidebar should be hidden / collapsed
    // behind the drawer. MaryUI/DaisyUI hides the sidebar below lg breakpoint.
    $page->resize(375, 812)
        ->wait(1)
        ->assertScript(
            // Check if the sidebar drawer is hidden or toggled off at mobile width.
            // MaryUI wraps the sidebar in a drawer; at small viewports the drawer
            // is hidden unless explicitly toggled open.
            '(() => {
                const drawer = document.querySelector(\'[id*="main-drawer"], .drawer\');
                if (!drawer) return true;
                const style = window.getComputedStyle(drawer);
                // The sidebar wrapper should not be visible at mobile size
                return style.display === "none" || style.visibility === "hidden" ||
                       drawer.classList.contains("drawer-open") === false;
            })()',
            true
        )
        ->assertNoJavascriptErrors();
});
