<?php

namespace Tests\Feature;

use App\View\Components\AccessibleMenuItem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #996 — روی هر صفحه دقیقاً یک لینک سایدبار فعال است، و همان لینکی است که
 * کاربر روی آن ایستاده. قاعده با شبیه‌سازی request واقعی سنجیده می‌شود تا لیست
 * رگرسیون از کد جدا نشود.
 */
class SidebarActiveLinkTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * همهٔ لینک‌های سایدبار → آیا `:exact` دارد؟
     * (باید با app.blade.php یکی بماند؛ تست drift در انتهای فایل همین را تضمین می‌کند.)
     *
     * @var array<string, bool>
     */
    private const LINKS = [
        '/search' => false, '/dashboard' => false,
        '/kargozini/estekhdams' => false, '/kargozini/radifs' => false,
        '/kargozini/tahsils' => false, '/kargozini/semats' => false,
        '/tickets/new' => false, '/tickets/new?category=bug' => false,
        '/tickets/new?category=support' => false, '/tickets/inbox' => false,
        '/monitoring' => false, '/todo' => false, '/units' => false,
        '/kargozini/persons' => false, '/hr-dashboard' => false, '/hr/org-chart' => false,
        '/map' => false, '/maps/unit' => false, '/maps/route' => false,
        '/maps/route2' => false, '/maps/county' => false, '/maps/point' => false,
        '/it/networks' => false, '/it/wireless' => false, '/it/zabbix-devices' => false,
        '/tools' => false, '/hardware' => false, '/maintenance' => false,
        '/reports/units' => false, '/reports/persons' => false, '/reports/todos' => false,
        '/reports/map-no-boundary' => false, '/reports/tickets' => false,
        '/activity-log' => false, '/users' => true, '/roles' => false,
        '/permissions' => false, '/profile' => false,
        '/users/changepassword' => false, '/settings' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pageProvider(): array
    {
        return [
            // صفحاتی که امروز چند هایلایت می‌گیرند (باگ اصلی)
            '/maps/route' => ['/maps/route', '/maps/route'],
            '/maps/route2' => ['/maps/route2', '/maps/route2'],
            '/maps/point' => ['/maps/point', '/maps/point'],
            '/maps/county' => ['/maps/county', '/maps/county'],
            '/maps/unit' => ['/maps/unit', '/maps/unit'],
            '/users/changepassword' => ['/users/changepassword', '/users/changepassword'],
            // صفحه‌ای که صفر هایلایت داشت
            '/dashboard' => ['/dashboard', '/dashboard'],
            // کوئری صفحهٔ جاری نباید هایلایت را بشکند (Livewire #[Url])
            '/users?page=2' => ['/users', '/users'],
            '/units?page=3' => ['/units', '/units'],
            '/reports/tickets?days=7' => ['/reports/tickets', '/reports/tickets'],
            '/monitoring?priority=high' => ['/monitoring', '/monitoring'],
            // صفحات فرزند بدون آیتم مستقل → والد
            '/units/chart' => ['/units', '/units'],
            '/units/7/map' => ['/units', '/units'],
            // میان‌برهای کوئری‌دار: صفحهٔ واقعی همان /tickets/new است
            '/tickets/new?category=bug' => ['/tickets/new', '/tickets/new'],
            '/tickets/new?category=support' => ['/tickets/new', '/tickets/new'],
            '/tickets/new' => ['/tickets/new', '/tickets/new'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_exactly_the_link_for_this_page_is_active(string $page, string $expected): void
    {
        $this->app->instance('request', Request::create(url($page), 'GET'));

        $active = [];

        foreach (self::LINKS as $link => $exact) {
            if ((new AccessibleMenuItem(link: $link, exact: $exact))->routeMatches()) {
                $active[] = $link;
            }
        }

        $this->assertSame(
            [$expected],
            $active,
            "روی {$page} باید دقیقاً «{$expected}» فعال باشد."
        );
    }

    /**
     * قاعده هرگز نباید از لیست سایدبار جدا شود: هر آیتم منو باید در LINKS باشد.
     * (`/select-context` و `/op` از این مقایسه بیرون‌اند: اولی icon-button است و
     * دومی یک `<a>` خام — هیچ‌کدام `x-menu-item` نیستند. سلکتور عمداً روی کلاس
     * آیتم منو فیلتر می‌کند تا همین دو مورد وارد مجموعه نشوند.)
     */
    public function test_the_link_list_matches_the_rendered_sidebar(): void
    {
        ['user' => $user] = $this->createUserWithUnit([
            'map', 'bw', 'manage_zabbix', 'manage_users', 'manage_hardware',
            'manage_personnel', 'view_hr_dashboard', 'manage_org_chart',
            'kargozini', 'organization', 'calendar', 'manage_roles',
            'create_ticket', 'view_assigned_tickets', 'view_all_tickets',
        ]);
        $this->actingAs($user);
        Session::put('current_unit_id', $user->units()->first()->id);

        $html = Blade::render('<x-layouts.app>محتوا</x-layouts.app>');

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(str_replace('<!DOCTYPE html>', '', $html));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $rendered = [];

        foreach ($xpath->query('//nav[@aria-label="منوی اصلی"]//a[contains(@class, "my-0.5")]') as $a) {
            $href = $a->getAttribute('href');   // MaryUI هربار `$this->link` را خام می‌ریزد
            if (str_starts_with($href, '/') && ! str_contains($href, '?')) {
                $rendered[] = $href;
            }
        }

        $rendered = array_values(array_unique($rendered));
        sort($rendered);

        $declared = array_values(array_filter(array_keys(self::LINKS), fn ($l) => ! str_contains($l, '?')));
        sort($declared);

        $this->assertSame($declared, $rendered, 'لیست لینک‌های تست با آیتم‌های واقعی سایدبار یکی نیست.');
    }
}
