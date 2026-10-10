<?php

namespace App\Support;

use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

/**
 * Removes a single named route from the router, whichever collection shape the
 * application is currently running (issue #951).
 *
 * `MaryServiceProvider::boot()` does an unconditional
 * `$this->loadRoutesFrom(__DIR__.'/../routes/web.php')`, so the package mounts
 * `POST /mary/upload` on every boot. That closure has no validation and reads
 * both the disk and the destination folder off the request body:
 *
 *     $disk   = $request->disk   ?? 'public';
 *     $folder = $request->folder ?? 'editor';
 *     $file = Storage::disk($disk)->put($folder, $request->file('file'), 'public');
 *
 * `config/mary.php` leaves `route_prefix` empty, so it sits at the app root
 * behind nothing but `auth` — a login, not a permission. Every other upload
 * call site in this app enforces `mimes:` + `max:` by hand, so the route
 * bypasses a policy that lives in the callers rather than at the boundary.
 *
 * The two collection shapes need different handling, which is the whole
 * reason this is a class rather than an inline loop in the provider:
 *
 *  - **RouteCollection** (uncached). Rebuild the collection without the route.
 *  - **CompiledRouteCollection** (`route:cache`). `match()` reads a flat
 *    `$compiled` array of already-indexed static prefixes, and its Route
 *    objects are instantiated fresh from `$attributes` on every call, so
 *    editing one changes nothing — a route "stripped" by mutating a Route
 *    still answers requests. The collection is rebuilt and recompiled
 *    instead, which regenerates both halves of `setCompiledRoutes()`.
 *
 * Either way the surviving routes keep serving normally, and a name that was
 * not registered is left untouched.
 *
 * The recompile costs ~4 ms against this app's ~130 routes (measured against
 * a 139-route cached collection), paid once per process and only while a
 * cache built before the fix is still on disk. A cache rebuilt by
 * `route:cache` no longer contains the route, so the guard finds nothing and
 * does no work.
 */
final class VendorRouteGuard
{
    /**
     * Strip the named route from the router's current collection.
     *
     * A no-op when the route is not registered.
     */
    public static function strip(Router $router, string $name): void
    {
        $routes = $router->getRoutes();

        if ($routes instanceof RouteCollection) {
            self::stripFromCollection($router, $routes, $name);

            return;
        }

        self::neutraliseInCompiledCollection($router, $routes, $name);
    }

    /**
     * Rebuild an uncached collection without the named route.
     *
     * Routes are re-added rather than removed in place: RouteCollection keeps
     * parallel `$routes[$method][$uri]`, `$allRoutes` and `$nameList` maps with
     * no removal method, so a fresh instance plus `add()` is the only way to
     * keep all three consistent.
     */
    private static function stripFromCollection(Router $router, RouteCollection $routes, string $name): void
    {
        $kept = new RouteCollection;
        $removed = false;

        foreach ($routes as $route) {
            if ($route->getName() === $name) {
                $removed = true;

                continue;
            }

            $kept->add($route);
        }

        if ($removed) {
            $router->setRoutes($kept);
        }
    }

    /**
     * Make the named route unmatchable inside a compiled collection.
     *
     * The route cannot simply be edited in place: `getRoutes()` here
     * instantiates fresh Route objects from `$attributes` on every call, so
     * a mutation would be discarded. The collection is instead rebuilt from
     * every route except the target and recompiled, which regenerates both
     * halves of `setCompiledRoutes()` — the flat `$compiled` matcher index and
     * the `$attributes` name map — so the URI genuinely stops matching.
     */
    private static function neutraliseInCompiledCollection(Router $router, mixed $routes, string $name): void
    {
        $found = false;
        $kept = new RouteCollection;

        foreach ($routes->getRoutes() as $route) {
            if ($route->getName() === $name) {
                $found = true;

                continue;
            }

            $kept->add($route);
        }

        if ($found) {
            $kept->refreshNameLookups();
            $kept->refreshActionLookups();

            $router->setCompiledRoutes($kept->compile());
        }
    }
}
