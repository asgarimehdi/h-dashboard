<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\View\Components\AppBrand;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('every help content section renders without errors', function (string $section) {
    $user = makeHelpUser();
    // Render each help content file standalone via the modal component path
    $content = view("components.help.content.{$section}")->render();
    expect($content)->toBeString()->not->toBeEmpty();
})->with([
    'dashboard', 'hardware', 'hardware-import', 'persons-import', 'personnel',
    'units', 'tickets', 'todos', 'reports', 'maps', 'settings', 'roles',
    'permissions', 'users', 'activity-log', 'networks', 'wireless', 'tools',
    'search', 'profile', 'hr-dashboard',
]);
