<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1246, step 5 — the four disease pages and their gate.
 *
 * `/disease/manage` (#1242) and `/disease/patients` (#1243) already existed;
 * this issue adds `/disease` and `/disease/create` under the SAME
 * `manage_diseases` gate, inside the `unit_context` group. `manage_diseases`
 * must be seeded before any of this works: Spatie answers `false` for an
 * unknown permission, so a missing seed turns every page into a silent 403.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

/** @return array<string, string> route name → URI */
function diseaseRouteMap(): array
{
    return [
        'disease.index' => '/disease',
        'disease.create' => '/disease/create',
        'disease.patients' => '/disease/patients',
        'disease.manage' => '/disease/manage',
    ];
}

test('all four disease route names resolve', function () {
    foreach (diseaseRouteMap() as $name => $uri) {
        expect(Route::has($name))->toBeTrue("Route {$name} is not registered.");

        expect(route($name, absolute: false))->toBe($uri);
    }
});

test('the two newly registered pages live inside the unit_context group', function () {
    foreach (['disease.index', 'disease.create'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        expect($middleware)->toContain('unit_context')
            ->and($middleware)->toContain('role_or_permission:manage_diseases');
    }
});

test('a manage_diseases holder can open all four pages', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);
    $this->actingAs($user);

    foreach (diseaseRouteMap() as $name => $uri) {
        $this->get($uri)->assertStatus(200);
    }
});

test('an organization-only holder gets 403 on every disease page', function () {
    ['user' => $user] = $this->createUserWithUnit(['organization']);
    $this->actingAs($user);

    foreach (diseaseRouteMap() as $name => $uri) {
        $this->get($uri)->assertStatus(403);
    }
});

test('a guest is redirected to login on every disease page', function () {
    foreach (diseaseRouteMap() as $name => $uri) {
        $this->get($uri)->assertRedirect('/login');
    }
});
