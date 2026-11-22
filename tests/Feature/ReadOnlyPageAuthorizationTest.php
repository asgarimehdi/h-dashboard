<?php

use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1632 — thirteen read-only Livewire pages relied solely on their
 * route's `role_or_permission` middleware, which runs exactly once on the full
 * page load and never on `POST /livewire/update`. A holder whose permission was
 * revoked mid-session could keep pulling the page's data from the open tab.
 *
 * Each component now carries one `boot()` guard. Livewire runs `boot()` on
 * mount AND on hydrate, before `mount()`/`hydrate()` and before any
 * client-callable public method, so a single guard closes the whole surface —
 * including plain public data methods such as `chartPayload()`, `loadData()`
 * or `getAllUnitsProperty()` that `$wire.call(...)` can invoke directly.
 *
 * The contract each revoke test proves:
 *
 *   1. MOUNT the component while the gate permission is still held.
 *   2. REVOKE the permission and flush Spatie's cache.
 *   3. Trigger a data re-fetch on the SAME mounted instance (the open-tab
 *      reproduction) and assert 403.
 *
 * The positive tests are not decoration: Spatie answers `false` for an unknown
 * permission name, so a typo would fail CLOSED and every negative test would
 * still pass while the page is locked for legitimate holders.
 *
 * Scope honesty: this is a data-leak fix for nine pages. `hr.dashboard`,
 * `it.networks` and `it.wireless` ship their data in public props at mount and
 * do not re-query on update, so for those three the guard is defense in depth
 * (the update endpoint stops accepting requests). The bulk GIS layer
 * (`/api/gis/*`) and the Zabbix layer (`/api/zabbix/*`) are already gated
 * per-request by `routes/api.php` and are not part of this component surface.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

/**
 * Mount while the gate is held, revoke, flush Spatie's cache, return the
 * ALREADY-MOUNTED instance — the open-tab reproduction.
 *
 * @param  array<string>  $abilities
 * @return array{0: Testable, 1: Unit}
 */
function revokedReadOnlyTab(string $component, array $abilities): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit($abilities);
    test()->actingAs($user);

    $instance = Livewire::test($component)->assertStatus(200);

    foreach ($abilities as $ability) {
        $user->revokePermissionTo($ability);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$instance, $unit];
}

dataset('readOnlyPages', [
    'reports.advanced' => ['reports.advanced', ['manage_personnel'], fn ($c, $u) => $c->call('chartPayload')],
    'reports.units' => ['reports.units', ['manage_personnel'], fn ($c, $u) => $c->call('chartPayload')],
    'reports.persons' => ['reports.persons', ['manage_personnel'], fn ($c, $u) => $c->call('chartPayload')],
    'reports.todos' => ['reports.todos', ['manage_personnel'], fn ($c, $u) => $c->call('chartPayload')],
    'reports.map-no-boundary' => ['reports.map-no-boundary', ['manage_personnel'], fn ($c, $u) => $c->call('getAllUnitsProperty')],
    'tickets.monitoring' => ['tickets.monitoring', ['view_all_tickets'], fn ($c, $u) => $c->call('loadData')],
    'activity-log.index' => ['activity-log.index', ['manage_users'], fn ($c, $u) => $c->call('loadData')],
    'units.chart' => ['units.chart', ['organization'], fn ($c, $u) => $c->call('loadData')],
    'hr.dashboard' => ['hr.dashboard', ['view_hr_dashboard'], fn ($c, $u) => $c->set('showHelpModal', true)],
    'hr.org-chart' => ['hr.org-chart', ['manage_org_chart'], fn ($c, $u) => $c->call('loadPersonCounts')],
    'map.map-dashboard' => ['map.map-dashboard', ['map'], fn ($c, $u) => $c->call('loadUnitDetails', $u->id)],
    'it.networks' => ['it.networks', ['map'], fn ($c, $u) => $c->set('showHelpModal', true)],
    'it.wireless' => ['it.wireless', ['map'], fn ($c, $u) => $c->call('itemIds')],
]);

test('an update request is refused after the page permission is revoked', function (string $component, array $abilities, Closure $probe) {
    [$instance, $unit] = revokedReadOnlyTab($component, $abilities);

    $probe($instance, $unit)->assertForbidden();
})->with('readOnlyPages');

test('a holder of the page permission can still mount and read', function (string $component, array $abilities, Closure $probe) {
    ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit($abilities);
    $this->actingAs($user);

    $instance = Livewire::test($component)->assertStatus(200);

    $probe($instance, $unit)->assertStatus(200);
})->with('readOnlyPages');

test('hr.org-chart accepts either half of its union gate', function (string $ability) {
    ['user' => $user] = $this->createUserWithUnit([$ability]);
    $this->actingAs($user);

    Livewire::test('hr.org-chart')->assertStatus(200);
})->with(['manage_org_chart', 'view_hr_dashboard']);

dataset('itUnionPages', [
    'networks+map' => ['it.networks', 'map'],
    'networks+bw' => ['it.networks', 'bw'],
    'wireless+map' => ['it.wireless', 'map'],
    'wireless+bw' => ['it.wireless', 'bw'],
]);

test('it.networks and it.wireless accept either half of their union gate', function (string $component, string $ability) {
    ['user' => $user] = $this->createUserWithUnit([$ability]);
    $this->actingAs($user);

    Livewire::test($component)->assertStatus(200);
})->with('itUnionPages');

test('a user with neither half of a union gate is refused', function (string $component) {
    ['user' => $user] = $this->createUserWithUnit([]);
    $this->actingAs($user);

    Livewire::test($component)->assertStatus(403);
})->with(['hr.org-chart', 'it.networks', 'it.wireless']);

test('every read-only page pins the same gate its route uses', function () {
    $single = [
        'resources/views/livewire/reports/advanced.blade.php' => 'manage_personnel',
        'resources/views/livewire/reports/units.blade.php' => 'manage_personnel',
        'resources/views/livewire/reports/persons.blade.php' => 'manage_personnel',
        'resources/views/livewire/reports/todos.blade.php' => 'manage_personnel',
        'resources/views/livewire/reports/map-no-boundary.blade.php' => 'manage_personnel',
        'resources/views/livewire/tickets/⚡monitoring.blade.php' => 'view_all_tickets',
        'resources/views/livewire/activity-log/index.blade.php' => 'manage_users',
        'resources/views/livewire/units/chart.blade.php' => 'organization',
        'resources/views/livewire/hr/dashboard.blade.php' => 'view_hr_dashboard',
        'resources/views/livewire/map/map-dashboard.blade.php' => 'map',
    ];

    foreach ($single as $file => $permission) {
        $contents = file_get_contents(base_path($file));
        $this->assertIsString($contents);
        $this->assertMatchesRegularExpression('/public function boot\(\): void/', $contents, "{$file} has no boot() guard");
        preg_match_all("/authorize\('([^']+)'\)/", $contents, $matches);
        $this->assertNotEmpty($matches[1], "{$file} has no authorize() call");
        foreach ($matches[1] as $found) {
            $this->assertSame($permission, $found, "{$file} authorizes '{$found}' instead of '{$permission}'");
        }
    }

    $union = [
        'resources/views/livewire/hr/org-chart.blade.php' => ['manage_org_chart', 'view_hr_dashboard'],
        'resources/views/livewire/it/networks.blade.php' => ['map', 'bw'],
        'resources/views/livewire/it/wireless.blade.php' => ['map', 'bw'],
    ];

    foreach ($union as $file => $abilities) {
        $contents = file_get_contents(base_path($file));
        $this->assertIsString($contents);
        $this->assertMatchesRegularExpression('/public function boot\(\): void/', $contents, "{$file} has no boot() guard");
        preg_match('/canAny\(\[([^\]]+)\]\)/', $contents, $matches);
        $this->assertNotEmpty($matches, "{$file} has no canAny() union guard");
        foreach ($abilities as $ability) {
            $this->assertStringContainsString("'{$ability}'", $matches[1], "{$file} is missing '{$ability}'");
        }
    }
});
