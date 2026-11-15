<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\View\Components\AppBrand;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(AppBrand::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Help system tests:
 * - Every page with a help button renders correctly
 * - The help modal opens and shows section-specific content
 * - No broken sections (all wired content files render)
 */
beforeEach(function () {
    $this->seedLookupTables();
    Session::flush();
});

function makeHelpUser(): User
{
    $unit = Unit::create(['name' => 'Test Unit']);
    $tId = DB::table('tahsils')->first()->id;
    $eId = DB::table('estekhdams')->first()->id;
    $sId = DB::table('semats')->first()->id;
    $rId = DB::table('radifs')->first()->id;
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

/**
 * Routed Livewire pages still waiting for their own help button.
 *
 * Phase 0 allowlist (#1535). Each rollout phase removes its own rows, so the
 * static contract starts enforcing the page the moment it is wired. Phase 4
 * leaves this list empty.
 *
 * @return list<string>
 */
function helpPagesPendingRollout(): array
{
    return [
        'disease.index', 'disease.create', 'disease.manage', 'disease.patients',
        'units.chart', 'units.map',
        'maps/route', 'maps/route2', 'maps/county', 'maps/point',
        'map.map-dashboard',
        'kargozini.estekhdam', 'kargozini.tahsil', 'kargozini.semat', 'kargozini.radif',
        'hr.org-chart',
        'it/zabbix-devices',
        'maintenance.index',
        'reports.advanced', 'reports.persons',
        'todo.todo',
        'select-context',
        'auth.changepassword',
    ];
}

/**
 * Routed pages that never own a help button in their own file.
 *
 * `auth.login` is public/pre-auth (the modal would ship the whole help payload
 * to an anonymous visitor); `hardware.index` is served by its `_toolbar`
 * partial, rendered by the same Livewire component.
 *
 * @return list<string>
 */
function helpPageExceptions(): array
{
    return ['auth.login', 'hardware.index'];
}

/**
 * Livewire component names from every `Route::livewire(...)` call in web.php.
 *
 * Commented routes are skipped: `web.php` still carries a commented
 * `auth.register` line and there is no such component.
 *
 * @return list<string>
 */
function routedLivewirePageComponents(): array
{
    $components = [];

    foreach (file(base_path('routes/web.php')) as $line) {
        $line = trim($line);

        if (str_starts_with($line, '//')) {
            continue;
        }

        if (preg_match("/Route::livewire\(\s*'[^']*'\s*,\s*'([^']+)'/", $line, $matches)) {
            $components[] = $matches[1];
        }
    }

    return array_values(array_unique($components));
}

function helpButtonCount(string $source): int
{
    return substr_count($source, '<x-help:button');
}

it('tools page renders with help button', function () {
    $this->seed(PermissionSeeder::class);
    $user = makeHelpUser();
    $user->givePermissionTo('manage_users');
    Livewire::actingAs($user)->test('tools.tools')
        ->assertOk()
        ->assertSee('ابزارهای مدیریتی');
});

it('search page renders with help button', function () {
    $user = makeHelpUser();
    Livewire::actingAs($user)->test('search.index')
        ->assertOk()
        ->assertSee('جستجوی کلی');
});

it('profile page renders with help button', function () {
    $user = makeHelpUser();
    Livewire::actingAs($user)->test('profile.index')
        ->assertOk()
        ->assertSee('پروفایل');
});

it('hardware page help modal opens via button', function () {
    $user = makeHelpUser();
    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->call('$set', 'showHelpModal', true)
        ->assertSet('showHelpModal', true);
});

it('does not ship the help modal body while the modal is closed', function () {
    $user = makeHelpUser();

    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->assertSet('showHelpModal', false)
        ->assertDontSee('نقشه‌های تعاملی و تحلیل مکانی')
        ->assertDontSee('راهنمای این بخش در دسترس نیست');
});

it('ships the help modal body on the same response that opens it', function () {
    $user = makeHelpUser();

    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->assertDontSee('نقشه‌های تعاملی و تحلیل مکانی')
        ->call('$set', 'showHelpModal', true)
        ->assertSet('showHelpModal', true)
        ->assertSee('نقشه‌های تعاملی و تحلیل مکانی');
});

it('opens the help modal with a server round-trip instead of a client-only assignment', function () {
    $user = makeHelpUser();

    // #1263: `$wire.showHelpModal = true` only mutates client state, so the
    // server never re-renders and the body (gated by @if in #1166) stays out.
    // $set() is a real Livewire action and commits the open flag.
    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->assertSee('$set(\'showHelpModal\', true)', false)
        ->assertDontSee('$wire.showHelpModal = true', false);
});

it('ships a materially smaller response while the help modal is closed', function () {
    $user = makeHelpUser();

    $closed = strlen(Livewire::actingAs($user)->test('hardware.index')->html());

    $open = strlen(
        Livewire::actingAs($user)->test('hardware.index')
            ->call('$set', 'showHelpModal', true)
            ->html()
    );

    expect($open - $closed)->toBeGreaterThan(20 * 1024);
});

it('keeps the unknown-section fallback when the help modal is open', function () {
    $user = makeHelpUser();

    Livewire::actingAs($user)->test('hardware.index')
        ->call('$set', 'showHelpModal', true)
        ->assertSee('راهنمای این بخش در دسترس نیست')
        ->assertSee('محتوای راهنما تعریف نشده است');
});

it('renders every registered help content section', function () {
    makeHelpUser();

    foreach (config('help.sections') as $section) {
        $content = view("components.help.content.{$section}")->render();

        expect($content)->toBeString()->not->toBeEmpty();
    }
});

it('has a content file for every registered help section', function () {
    foreach (config('help.sections') as $section) {
        expect(resource_path("views/components/help/content/{$section}.blade.php"))->toBeFile();
    }
});

it('registers every help content file', function () {
    $files = collect(File::files(resource_path('views/components/help/content')))
        ->map(fn ($file) => basename($file->getFilename(), '.blade.php'))
        ->sort()
        ->values()
        ->all();

    $registered = collect(config('help.sections'))->sort()->values()->all();

    expect($files)->toBe($registered);
});

it('gives every routed page a help button except the allowlist', function () {
    $finder = app('livewire.finder');
    $allowed = [...helpPageExceptions(), ...helpPagesPendingRollout()];
    $missing = [];
    $duplicated = [];

    $components = routedLivewirePageComponents();
    expect($components)->not->toBeEmpty();

    foreach ($components as $component) {
        if (in_array($component, $allowed, true)) {
            continue;
        }

        $path = $finder->resolveSingleFileComponentPath($component);

        if ($path === null) {
            $missing[] = "{$component}: no single-file component resolved";

            continue;
        }

        $count = helpButtonCount(file_get_contents($path));

        if ($count === 0) {
            $missing[] = "{$component}: no help button ({$path})";
        } elseif ($count > 1) {
            $duplicated[] = "{$component}: {$count} help buttons ({$path})";
        }
    }

    expect($missing)->toBe([])->and($duplicated)->toBe([]);
});

it('points every help button at a registered section', function () {
    $registered = config('help.sections');
    $unknown = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/<x-help:button\b[^>]*\bsection="([^"]+)"/s', $file->getContents(), $matches);

        foreach ($matches[1] as $section) {
            if (! in_array($section, $registered, true)) {
                $unknown[] = "{$file->getRelativePathname()}: {$section}";
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('maps every rolled-out page to its section', function (string $component, string $section) {
    $path = app('livewire.finder')->resolveSingleFileComponentPath($component);
    expect($path)->not->toBeNull();

    $source = file_get_contents($path);

    if (in_array($component, helpPagesPendingRollout(), true)) {
        // Not wired yet; wiring the page without removing its allowlist row
        // must fail here so the static contract cannot silently go stale.
        expect(helpButtonCount($source))->toBe(0);

        return;
    }

    expect($source)->toMatch('/<x-help:button\b[^>]*\bsection="'.preg_quote($section, '/').'"/s');
})->with([
    ['disease.index', 'disease'],
    ['disease.create', 'disease'],
    ['disease.manage', 'disease'],
    ['disease.patients', 'disease'],
    ['units.chart', 'units'],
    ['units.map', 'units'],
    ['maps/route', 'maps'],
    ['maps/route2', 'maps'],
    ['maps/county', 'maps'],
    ['maps/point', 'maps'],
    ['map.map-dashboard', 'maps'],
    ['kargozini.estekhdam', 'personnel'],
    ['kargozini.tahsil', 'personnel'],
    ['kargozini.semat', 'personnel'],
    ['kargozini.radif', 'personnel'],
    ['hr.org-chart', 'org-chart'],
    ['it/zabbix-devices', 'zabbix-devices'],
    ['maintenance.index', 'maintenance'],
    ['reports.advanced', 'reports'],
    ['reports.persons', 'reports'],
    ['todo.todo', 'todos'],
    ['select-context', 'select-context'],
    ['auth.changepassword', 'change-password'],
]);
