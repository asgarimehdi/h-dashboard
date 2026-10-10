<?php

namespace App\Providers;

use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Observers\HardwareAuditObserver;
use App\Services\CacheInvalidationService;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\Zabbix\ServiceZabbixClient;
use App\Services\Zabbix\ZabbixClient;
use App\Services\ZabbixService;
use App\Support\VendorRouteGuard;
use App\View\Components\AccessibleMenuItem;
use App\View\Components\AccessibleTable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CacheInvalidationServiceInterface::class,
            CacheInvalidationService::class
        );

        // Zabbix transport behind a typed interface (#741). Resolved per call
        // so tests that bind a `ZabbixService` instance still get their mock
        // wrapped — see ServiceZabbixClient's docblock.
        $this->app->bind(
            ZabbixClient::class,
            fn ($app) => new ServiceZabbixClient($app->make(ZabbixService::class))
        );
    }

    public function boot(): void
    {
        // Per-user API rate limiter (replaces IP-based throttle)
        RateLimiter::for('api-user', function ($request) {
            return Limit::perMinute(60)->by(
                $request->user()->id ?? $request->ip()
            );
        });

        // Register anonymous help components with colon syntax for Blade
        Blade::component('components.help.button', 'help:button');
        Blade::component('components.help.modal', 'help:modal');

        // #957 (step 2): maryUI's sortable `<th>` carries no `aria-sort` and no
        // `tabindex`, and it is built inside the vendor component's own render()
        // heredoc — no call-site attribute can reach it. Re-registering the
        // alias is the only faithful fix; `<x-table>` keeps working unchanged
        // because AccessibleTable extends Mary's Table.
        //
        // #957 (step 2, second half): the sidebar marks the current page with a
        // CSS class only, so a screen reader reports no location at all.
        // `mary-active-menu` must survive — Mary's MenuSub greps the rendered
        // child markup for it to decide that a submenu is open.
        //
        // Both must run AFTER MaryServiceProvider::boot(), which registers the
        // same aliases. It does: package providers boot before
        // bootstrap/providers.php.
        //
        // Deploys must run `view:clear` before `view:cache` — Blade keys a
        // compiled view on the source file's mtime, not on the alias table, so
        // a cache carried over from before this change keeps Mary's markup.
        Blade::component('table', AccessibleTable::class);
        Blade::component('menu-item', AccessibleMenuItem::class);

        // Register help-content components dynamically with colon syntax
        $helpContents = [
            'dashboard',
            'hardware',
            'hardware-import',
            'persons-import',
            'personnel',
            'units',
            'tickets',
            'todos',
            'reports',
            'maps',
            'settings',
            'roles',
            'permissions',
            'users',
            'activity-log',
            'networks',
            'wireless',
            'tools',
            'search',
            'profile',
            'hr-dashboard',
        ];

        foreach ($helpContents as $content) {
            Blade::component("components.help.content.{$content}", "help-content:{$content}");
        }

        // Register Hardware Audit observer for field-level change tracking
        // (single unified audit source — replaces the old HardwareHistory observer)
        Hardware::observe(HardwareAuditObserver::class);

        // Invalidate report caches on Todo/Ticket changes (Issue #320)
        $invalidate = function (array $namespaces) {
            $cache = app(CacheInvalidationServiceInterface::class);
            $cache->batch(function () use ($namespaces, $cache) {
                foreach ($namespaces as $ns) {
                    $cache->increment($ns);
                }
            });
        };

        $todoNamespaces = ['report_todos', 'dashboard'];
        Todo::created(fn () => $invalidate($todoNamespaces));
        Todo::updated(fn () => $invalidate($todoNamespaces));
        Todo::deleted(fn () => $invalidate($todoNamespaces));

        $ticketNamespaces = ['report_tickets', 'gis', 'calendar', 'dashboard'];
        Ticket::created(fn () => $invalidate($ticketNamespaces));
        Ticket::updated(fn () => $invalidate($ticketNamespaces));
        Ticket::deleted(fn () => $invalidate($ticketNamespaces));

        // Invalidate units report + hierarchy + GIS + HR caches on Unit changes (Issues #340, #372, #391)
        $unitNamespaces = ['report_units', 'unit_hierarchy', 'gis', 'hr_stats'];
        Unit::created(fn () => $invalidate($unitNamespaces));
        Unit::updated(fn () => $invalidate($unitNamespaces));
        Unit::deleted(fn () => $invalidate($unitNamespaces));

        // Issue #951: the vendored maryUI upload endpoint is not mounted. See
        // App\Support\VendorRouteGuard for why the route has to be stripped
        // from the router rather than left unregistered-and-guessed-at.
        //
        // The hook is registered from INSIDE a booted callback on purpose.
        // Routes are populated by booted callbacks too — AppRouteServiceProvider
        // loads routes/web.php in one, and with `route:cache` the cached file is
        // `require`d in a further one — so a hook registered directly from
        // boot() can run BEFORE the vendor route exists and strip nothing.
        // Application::fireAppCallbacks() re-checks the callback count on every
        // pass, so registering again from inside a booted callback is what
        // places this after them in both the cached and uncached paths.
        $this->app->booted(function () {
            $this->app->booted(fn () => VendorRouteGuard::strip(
                $this->app->make(Router::class),
                'mary.upload'
            ));
        });
    }
}
