<?php

namespace Tests\Unit;

use App\Support\VendorRouteGuard;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| VendorRouteGuard (#951)
|--------------------------------------------------------------------------
|
| The guard strips a named route from WHATEVER collection shape the router
| currently holds, because the app can be running with cached routes
| (CompiledRouteCollection) or without (RouteCollection) and the two need
| different handling — a compiled collection matches from a flat pre-built
| array that mutating a Route instance cannot repair.
|
*/

uses(TestCase::class);

function routeCollectionWithUpload(): RouteCollection
{
    $routes = new RouteCollection;

    $routes->add(Route::post('/mary/upload', fn () => 'written')->name('mary.upload'));
    $routes->add(Route::get('/mary/toogle-sidebar', fn () => 'collapsed')->name('mary.toogle-sidebar'));

    return $routes;
}

test('it strips the named route from an uncached route collection', function () {
    $router = app(Router::class);
    $router->setRoutes(routeCollectionWithUpload());

    VendorRouteGuard::strip($router, 'mary.upload');

    expect($router->getRoutes())->toBeInstanceOf(RouteCollection::class)
        ->and($router->has('mary.upload'))->toBeFalse()
        ->and($router->has('mary.toogle-sidebar'))->toBeTrue()
        ->and($router->getRoutes()->getRoutes())->toHaveCount(1);
});

test('it strips the named route from a compiled (cached) route collection', function () {
    $router = app(Router::class);
    $source = routeCollectionWithUpload();
    $source->refreshNameLookups();
    $source->refreshActionLookups();

    $router->setCompiledRoutes($source->compile());

    expect($router->getRoutes())->toBeInstanceOf(CompiledRouteCollection::class)
        ->and($router->has('mary.upload'))->toBeTrue('precondition: the cached matcher still serves it');

    VendorRouteGuard::strip($router, 'mary.upload');

    expect($router->has('mary.upload'))->toBeFalse()
        ->and($router->has('mary.toogle-sidebar'))->toBeTrue()
        ->and($router->getRoutes()->getRoutes())->toHaveCount(1);
});

test('a request to the stripped uri no longer reaches the vendor closure', function () {
    $router = app(Router::class);
    $source = routeCollectionWithUpload();
    $source->refreshNameLookups();
    $source->refreshActionLookups();
    $router->setCompiledRoutes($source->compile());

    VendorRouteGuard::strip($router, 'mary.upload');

    $this->post('/mary/upload', ['disk' => 'local'])->assertNotFound();
});

test('it leaves a collection alone when the named route is absent', function () {
    $router = app(Router::class);
    $routes = new RouteCollection;
    $routes->add(Route::get('/mary/toogle-sidebar', fn () => 'collapsed')->name('mary.toogle-sidebar'));
    $router->setRoutes($routes);

    $before = $router->getRoutes();

    VendorRouteGuard::strip($router, 'mary.upload');

    expect($router->getRoutes())->toBe($before);
});
