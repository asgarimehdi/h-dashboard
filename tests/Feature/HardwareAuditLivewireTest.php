<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

uses(Tests\TestCase::class)
    ->in('Feature');

it('hardware page renders with unified audit history modal', function () {
    $unit = Unit::create(['name' => 'Test Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    $nCode = (string) random_int(1000000000, 2147483647);
    Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    Livewire::actingAs($user)->test('hardware.index')
        ->assertOk()
        ->assertSee('شناسنامه سخت افزار');
});