<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * یک آیتم منو و مسیرِ همان آیتم باید با «همان» مجوز گیت شوند.
 *
 * چرا این تست وجود دارد: قبل از این تغییر چند ناهمخوانی واقعی داشتیم —
 * `/tools` در منو زیر `bw` رندر می‌شد ولی روتش `manage_users` می‌خواست؛
 * لینک «کش سرور» بدون هیچ گیتی رندر می‌شد ولی روتش فقط خارج از production
 * تعریف شده؛ «شبکه‌ها/وایرلس‌ها» زیر `bw` رندر می‌شدند ولی روتشان `map` بود؛
 * و زیرمنوی «گزارش‌ها» هیچ گیتی نداشت. هر مورد اینجا به‌صورت داده‌ای
 * (href → مجوزهای لازم) پین می‌شود تا دوباره منحرف نشود.
 */
class SidebarPermissionsTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * href در سایدبار → مجوزهایی که باید آن را نشان دهند (هرکدام کافی است).
     * این نگاشت باید با میدل‌ویر همان مسیر در routes/web.php یکی باشد.
     *
     * @var array<string, array<int, string>>
     */
    private const LINKS = [
        '/it/networks' => ['map', 'bw'],
        '/it/wireless' => ['map', 'bw'],
        '/it/zabbix-devices' => ['manage_zabbix'],
        '/tools' => ['manage_users'],
        '/users' => ['manage_users'],
        '/activity-log' => ['manage_users'],
        '/hardware' => ['manage_hardware'],
        '/maintenance' => ['manage_hardware'],
        '/reports/units' => ['manage_personnel'],
        '/reports/persons' => ['manage_personnel'],
        '/reports/tickets' => ['manage_personnel'],
        '/maps/unit' => ['map'],
        '/hr-dashboard' => ['view_hr_dashboard'],
        '/hr/org-chart' => ['manage_org_chart', 'view_hr_dashboard'],
        '/kargozini/persons' => ['kargozini'],
        '/units' => ['organization'],
        '/todo' => ['calendar'],
        '/roles' => ['manage_roles'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();
    }

    /**
     * سایدبار را برای کاربری با مجوزهای داده‌شده رندر و HTML را برمی‌گرداند.
     */
    private function sidebarFor(array $permissions, string $env = 'testing'): string
    {
        ['user' => $user] = $this->createUserWithUnit($permissions);

        $this->actingAs($user);
        Session::put('current_unit_name', 'واحد تست');

        $previousEnv = $this->app['env'];
        $this->app['env'] = $env;

        try {
            return Blade::render('<x-layouts.app>محتوا</x-layouts.app>');
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }

    /**
     * @return array<int, string>
     */
    private function permissionPool(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::LINKS))));
    }

    public function test_every_menu_link_needs_the_permission_its_own_route_requires(): void
    {
        $htmlPerPermission = [];

        foreach ($this->permissionPool() as $permission) {
            $htmlPerPermission[$permission] = $this->sidebarFor([$permission]);
        }

        foreach (self::LINKS as $href => $required) {
            $needle = 'href="'.$href.'"';

            // دارنده‌ی هر یک از مجوزهای لازم، لینک را می‌بیند…
            foreach ($required as $permission) {
                $this->assertStringContainsString(
                    $needle,
                    $htmlPerPermission[$permission],
                    "کاربر با مجوز «{$permission}» باید آیتم «{$href}» را در سایدبار ببیند."
                );
            }

            // …و هیچ مجوز بی‌ربطی نباید آن را نشان دهد (لینکِ بی‌دسترسی = ۴۰۳).
            foreach ($this->permissionPool() as $permission) {
                if (in_array($permission, $required, true)) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    $needle,
                    $htmlPerPermission[$permission],
                    "کاربر با مجوز «{$permission}» نباید آیتم «{$href}» را ببیند؛ روتش این مجوز را قبول نمی‌کند."
                );
            }
        }
    }

    public function test_every_menu_link_is_reachable_for_its_permission_holder(): void
    {
        foreach (self::LINKS as $href => $required) {
            // مسیرهایی که پارامتر ندارند با همان href قابل فراخوانی‌اند.
            ['user' => $user] = $this->createUserWithUnit([$required[0]]);

            $status = $this->actingAs($user)->get($href)->getStatusCode();

            $this->assertNotSame(
                403,
                $status,
                "دارنده‌ی «{$required[0]}» به «{$href}» دسترسی ندارد ({$status}) — گیت منو و روت یکی نیست."
            );
        }
    }

    public function test_a_perm_without_any_of_the_required_ones_gets_403(): void
    {
        // مدیریت گزارش‌ها، ابزارها و سخت‌افزار نباید با مجوزی دیگر باز شود.
        ['user' => $user] = $this->createUserWithUnit(['calendar']);

        foreach (['/reports/units', '/tools', '/hardware', '/it/zabbix-devices', '/hr-dashboard'] as $path) {
            $this->actingAs($user)->get($path)->assertStatus(403);
        }
    }

    public function test_op_cache_link_is_hidden_outside_local_environments(): void
    {
        // /op فقط خارج از production تعریف شده (routes/web.php).
        $production = $this->sidebarFor(['op-cache'], 'production');
        $this->assertStringNotContainsString('href="/op"', $production);

        $local = $this->sidebarFor(['op-cache'], 'local');
        $this->assertStringContainsString('href="/op"', $local);

        // و بدون مجوز، حتی در محیط محلی هم لینک نیست.
        $noPermission = $this->sidebarFor(['calendar'], 'local');
        $this->assertStringNotContainsString('href="/op"', $noPermission);
    }

    public function test_org_chart_accepts_both_its_own_permission_and_the_hr_dashboard_one(): void
    {
        // دسترسی فعلی (view_hr_dashboard) نباید از دست برود و مجوز قبلاً مرده‌ی
        // manage_org_chart باید واقعاً کار کند. کاربر به واحد متصل است تا
        // ValidateUnitContext و «دسترسی سازمانی» هر دو برقرار باشند.
        ['user' => $orgChartOnly] = $this->createUserWithUnit(['manage_org_chart']);
        $this->actingAs($orgChartOnly)->get('/hr/org-chart')->assertOk();

        ['user' => $hrOnly] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->actingAs($hrOnly)->get('/hr/org-chart')->assertOk();
    }

    public function test_it_pages_accept_map_or_bw(): void
    {
        // منو و روت قبلاً یکی `bw` و دیگری `map` بود؛ حالا هر دو می‌پذیرند.
        ['user' => $mapOnly] = $this->createUserWithUnit(['map']);
        $this->actingAs($mapOnly)->get('/it/networks')->assertOk();
        $this->actingAs($mapOnly)->get('/it/wireless')->assertOk();

        ['user' => $bwOnly] = $this->createUserWithUnit(['bw']);
        $this->actingAs($bwOnly)->get('/it/networks')->assertOk();
        $this->actingAs($bwOnly)->get('/it/wireless')->assertOk();

        ['user' => $neither] = $this->createUserWithUnit(['calendar']);
        $this->actingAs($neither)->get('/it/networks')->assertStatus(403);
    }

    public function test_reports_are_gated_but_activity_log_stays_on_manage_users(): void
    {
        ['user' => $reportsHolder] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($reportsHolder)->get('/reports/units')->assertOk();

        // «گزارش فعالیت» بخشی از گروه گزارش‌هاست ولی روتش `manage_users` است.
        ['user' => $manageUsersOnly] = $this->createUserWithUnit(['manage_users']);

        $this->actingAs($manageUsersOnly)->get('/activity-log')->assertOk();
        $this->actingAs($manageUsersOnly)->get('/reports/units')->assertStatus(403);
    }

    public function test_unit_manager_role_keeps_report_access(): void
    {
        // RoleSeeder نقشی برای unit_manager نمی‌سازد (آن نقش روی دیتابیس هر محیط
        // ساخته می‌شود)؛ پس همان مجوزی که به نقش داده شده اینجا مستقیم داده می‌شود.
        ['user' => $unitManager] = $this->createUserWithUnit(['manage_personnel']);

        $this->actingAs($unitManager)->get('/reports/units')->assertOk();

        $this->assertTrue($unitManager->fresh()->can('manage_personnel'));
    }
}
