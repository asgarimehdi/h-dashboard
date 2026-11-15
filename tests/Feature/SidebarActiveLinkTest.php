<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #996 — روی هر صفحه دقیقاً یک لینک سایدبار فعال است، و همان لینکی است که
 * کاربر روی آن ایستاده.
 *
 * #1531 — رفتار از رندر واقعی سایدبار سنجیده می‌شود، نه از یک ستون `exact` در
 * همین فایل. ستون `exact` می‌توانست سبز شود بدون اینکه Blade عوض شده باشد؛
 * رندر همان چیزی است که کاربر می‌بیند.
 */
class SidebarActiveLinkTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /**
     * همهٔ لینک‌های سایدبار — فقط فهرست، بدون ادعای `exact`. تست drift انتهای
     * فایل همین فهرست را با DOM سایدبار یکی می‌کند.
     *
     * @var array<int, string>
     */
    private const LINKS = [
        '/search', '/dashboard',
        '/kargozini/estekhdams', '/kargozini/radifs',
        '/kargozini/tahsils', '/kargozini/semats',
        '/tickets/new', '/tickets/new?category=bug',
        '/tickets/new?category=support', '/tickets/inbox',
        '/monitoring', '/todo', '/units',
        '/kargozini/persons', '/hr-dashboard', '/hr/org-chart',
        '/disease', '/disease/create', '/disease/patients', '/disease/manage',
        '/map', '/maps/unit', '/maps/route',
        '/maps/route2', '/maps/county', '/maps/point',
        '/it/networks', '/it/wireless', '/it/zabbix-devices',
        '/tools', '/tools/backup', '/hardware', '/maintenance',
        '/reports/units', '/reports/persons', '/reports/todos',
        '/reports/map-no-boundary', '/reports/tickets',
        '/activity-log', '/users', '/roles',
        '/permissions', '/profile',
        '/users/changepassword', '/settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();

        // `manage_diseases` گروه بیماری‌ها و `admin` آیتم بک‌آپ را رندر می‌کند؛
        // بدون این دو، تست drift آن لینک‌ها را در DOM نمی‌بیند.
        ['user' => $user] = $this->createUserWithUnit([
            'map', 'bw', 'manage_zabbix', 'manage_users', 'manage_hardware',
            'manage_personnel', 'view_hr_dashboard', 'manage_org_chart',
            'kargozini', 'organization', 'calendar', 'manage_roles',
            'create_ticket', 'view_assigned_tickets', 'view_all_tickets',
            'manage_diseases',
        ], 'admin');

        $this->actingAs($user);
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
            // #1531 — والد و فرزندانش هر دو ورودی سایدبار دارند
            '/disease' => ['/disease', '/disease'],
            '/disease/create' => ['/disease/create', '/disease/create'],
            '/disease/manage' => ['/disease/manage', '/disease/manage'],
            '/disease/patients' => ['/disease/patients', '/disease/patients'],
            '/tools' => ['/tools', '/tools'],
            '/tools/backup' => ['/tools/backup', '/tools/backup'],
            // ردیف مرزی: فرزند بدون ورودی مستقل → والد
            '/tools/backup/full' => ['/tools/backup', '/tools/backup'],
            // صفحه‌ای که صفر هایلایت داشت
            '/dashboard' => ['/dashboard', '/dashboard'],
            // کوئری صفحهٔ جاری نباید هایلایت را بشکند (Livewire #[Url])
            '/users?page=2' => ['/users', '/users'],
            '/units?page=3' => ['/units', '/units'],
            '/reports/tickets?days=7' => ['/reports/tickets', '/reports/tickets'],
            '/monitoring?priority=high' => ['/monitoring', '/monitoring'],
            // صفحات فرزند بدون آیتم مستقل → والد
            '/units/chart' => ['/units', '/units'],
            '/units/export' => ['/units', '/units'],
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
        $this->assertSame(
            [$expected],
            $this->renderedActiveLinks($page),
            "روی {$page} باید دقیقاً «{$expected}» فعال باشد."
        );
    }

    /**
     * #1531 — هیچ روت GETای نباید دو ورودی سایدبار را همزمان فعال کند.
     * همین هارنس، شش مسیر را پیش از فیکس پیدا می‌کرد.
     */
    public function test_no_get_route_activates_two_sidebar_links(): void
    {
        $offenders = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // زیرساخت و API سایدبار ندارند (هش livewire هر نصب متفاوت است).
            if (Str::startsWith($uri, ['api/', 'livewire', '_boost/', 'storage/'])) {
                continue;
            }

            // `/` فقط به `/dashboard` ریدایرکت می‌شود و صفحه‌ای رندر نمی‌کند.
            if ($uri === '/') {
                continue;
            }

            $path = '/'.(string) preg_replace('/\{[^}]+\}/', '1', $uri);
            $active = $this->renderedActiveLinks($path);

            if (count($active) > 1) {
                $offenders[$uri] = $active;
            }
        }

        $this->assertSame([], $offenders, 'این روت‌ها بیش از یک لینک سایدبار را فعال می‌کنند.');
    }

    /**
     * لینک‌هایی که سایدبار واقعاً روی این صفحه فعال رندر می‌کند.
     *
     * @return array<int, string>
     */
    private function renderedActiveLinks(string $page): array
    {
        $this->app->instance('request', Request::create(url($page), 'GET'));

        return $this->activeLinksIn(Blade::render('<x-layouts.app>محتوا</x-layouts.app>'));
    }

    /**
     * @return array<int, string>
     */
    private function activeLinksIn(string $html): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(str_replace('<!DOCTYPE html>', '', $html));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//nav[@aria-label="منوی اصلی"]//a[contains(@class, "mary-active-menu")]');

        if ($nodes === false) {
            return [];
        }

        $active = [];

        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $active[] = $node->getAttribute('href');
            }
        }

        return $active;
    }

    /**
     * قاعده هرگز نباید از لیست سایدبار جدا شود: هر آیتم منو باید در LINKS باشد.
     * (`/select-context` و `/op` از این مقایسه بیرون‌اند: اولی icon-button است و
     * دومی یک `<a>` خام — هیچ‌کدام `x-menu-item` نیستند. سلکتور عمداً روی کلاس
     * آیتم منو فیلتر می‌کند تا همین دو مورد وارد مجموعه نشوند.)
     */
    public function test_the_link_list_matches_the_rendered_sidebar(): void
    {
        $this->app->instance('request', Request::create(url('/dashboard'), 'GET'));

        $html = Blade::render('<x-layouts.app>محتوا</x-layouts.app>');

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(str_replace('<!DOCTYPE html>', '', $html));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $rendered = [];

        $nodes = $xpath->query('//nav[@aria-label="منوی اصلی"]//a[contains(@class, "my-0.5")]');

        if ($nodes !== false) {
            foreach ($nodes as $a) {
                if (! $a instanceof \DOMElement) {
                    continue;
                }

                $href = $a->getAttribute('href');   // MaryUI هربار `$this->link` را خام می‌ریزد
                if (str_starts_with($href, '/') && ! str_contains($href, '?')) {
                    $rendered[] = $href;
                }
            }
        }

        $rendered = array_values(array_unique($rendered));
        sort($rendered);

        $declared = array_values(array_filter(self::LINKS, fn ($l) => ! str_contains($l, '?')));
        sort($declared);

        $this->assertSame($declared, $rendered, 'لیست لینک‌های تست با آیتم‌های واقعی سایدبار یکی نیست.');
    }
}
