<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateUnitContext;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

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

    $middleware = new ValidateUnitContext();
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    expect($response)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
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

    $middleware = new ValidateUnitContext();
    $response = $middleware->handle($request, fn ($req) => redirect('/dashboard'));

    expect($response)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
    expect($response->getTargetUrl())->toContain('/select-context');
});
