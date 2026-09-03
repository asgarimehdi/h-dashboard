<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateUnitContext;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

covers(ValidateUnitContext::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
});

test('validate unit context sets unit from person when missing', function () {
    $unit = Unit::create(['name' => 'واحد تست']);
    $person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);
    $user = User::factory()->create(['n_code' => '1234567890']);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);

    $middleware = new ValidateUnitContext;
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    expect($response)->toBeInstanceOf(RedirectResponse::class);
});

test('validate unit context redirects to select context when multiple units and none selected', function () {
    $unit = Unit::create(['name' => 'واحد تست']);
    $user = User::factory()->create();
    $user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);
    $secondUnit = Unit::create(['name' => 'واحد دوم']);
    $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    Session::forget('current_unit_id');

    $middleware = new ValidateUnitContext;
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toContain('/select-context');
});

test('validate unit context passes through when session unit is accessible', function () {
    $unit = Unit::create(['name' => 'واحد تست']);
    $user = User::factory()->create();
    $user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    Session::put('current_unit_id', $unit->id);
    Session::put('current_unit_name', $unit->name);

    $middleware = new ValidateUnitContext;
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    // Has access to the session unit: passes straight through to $next.
    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toContain('/dashboard');
    // Session is NOT cleared when the unit is valid.
    expect(Session::get('current_unit_id'))->toBe($unit->id);
});

test('validate unit context forgets session unit when user lacks access', function () {
    $ownUnit = Unit::create(['name' => 'واحد تست']);
    $foreignUnit = Unit::create(['name' => 'واحد بیگانه']);
    $user = User::factory()->create();
    $user->units()->attach($ownUnit->id, ['role' => 'responsible', 'is_primary' => true]);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    Session::put('current_unit_id', $foreignUnit->id);
    Session::put('current_unit_name', $foreignUnit->name);

    $middleware = new ValidateUnitContext;
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    // No longer has the forbidden unit in session; falls back to single-unit select.
    expect(Session::get('current_unit_id'))->toBe($ownUnit->id);
    expect(Session::get('current_unit_name'))->toBe($ownUnit->name);
    // With exactly one accessible unit, it auto-selects and passes through.
    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toContain('/dashboard');
});

test('validate unit context auto-selects the single unit when none selected', function () {
    $unit = Unit::create(['name' => 'واحد تست']);
    $user = User::factory()->create();
    $user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    Session::forget('current_unit_id');
    Session::forget('current_unit_name');

    $middleware = new ValidateUnitContext;
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    // Single unit is auto-selected into the session and the request passes through.
    expect(Session::get('current_unit_id'))->toBe($unit->id);
    expect(Session::get('current_unit_name'))->toBe($unit->name);
    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toContain('/dashboard');
});

test('validate unit context redirects to select context with multiple units', function () {
    $unit = Unit::create(['name' => 'واحد تست']);
    $user = User::factory()->create();
    $user->units()->attach($unit->id, ['role' => 'responsible', 'is_primary' => true]);
    $secondUnit = Unit::create(['name' => 'واحد دوم']);
    $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    Session::forget('current_unit_id');
    Session::forget('current_unit_name');

    $middleware = new ValidateUnitContext;
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    // More than one unit and none selected: must redirect to the picker.
    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getTargetUrl())->toContain('/select-context');
    expect(Session::get('current_unit_id'))->toBeNull();
});
