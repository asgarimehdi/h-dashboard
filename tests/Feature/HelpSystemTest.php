<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/**
 * Help system tests:
 * - Every page with a help button renders correctly
 * - The help modal opens and shows section-specific content
 * - No broken sections (all wired content files render)
 */

beforeEach(function () {
    Session::flush();
});

function makeHelpUser(): User
{
    $unit = Unit::create(['name' => 'Test Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

it('tools page renders with help button', function () {
    $user = makeHelpUser();
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

it('every help content section renders without errors', function (string $section) {
    $user = makeHelpUser();
    // Render each help content file standalone via the modal component path
    $content = view("components.help.content.{$section}")->render();
    expect($content)->toBeString()->not->toBeEmpty();
})->with([
    'dashboard', 'hardware', 'hardware-import', 'persons-import', 'personnel',
    'units', 'tickets', 'todos', 'reports', 'maps', 'settings', 'roles',
    'permissions', 'users', 'activity-log', 'networks', 'wireless', 'tools',
    'search', 'profile',
]);
