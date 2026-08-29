<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Issue #201: hardware Livewire component person search/validation
 * must enforce organizational scope — no cross-unit person data leakage.
 */
beforeEach(function () {
    Session::flush();
});

function makeUnitAndPerson(string $unitName, string $nCode, string $fName, string $lName): array
{
    $unit = Unit::create(['name' => $unitName]);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    Person::create([
        'n_code' => $nCode,
        'f_name' => $fName,
        'l_name' => $lName,
        't_id' => $tId,
        'e_id' => $eId,
        's_id' => $sId,
        'r_id' => $rId,
        'u_id' => $unit->id,
    ]);

    return [$unit, Person::where('n_code', $nCode)->first()];
}

function makeUserInUnit(Unit $unit): User
{
    // User must correspond to an existing person (users.n_code FK)
    $nCode = (string) fake()->unique()->numerify('##########');
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'Test',
        'l_name' => 'User',
        't_id' => $tId,
        'e_id' => $eId,
        's_id' => $sId,
        'r_id' => $rId,
        'u_id' => $unit->id,
    ]);
    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

it('person search only returns persons within the user\'s organizational scope', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '1111111111', 'Ali', 'Rezaei');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '2222222222', 'Sara', 'Ahmadi');

    $user = makeUserInUnit($ownUnit);

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('personSearch', '2222') // other unit's n_code prefix
        ->assertSet('personResults', []); // must NOT see Unit B person

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('personSearch', 'Ali')
        ->assertSet('personResults', [
            ['n_code' => '1111111111', 'name' => 'Ali Rezaei'],
        ]);
});

it('person search by n_code respects scope', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '3333333333', 'Mohammad', 'Hosseini');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '4444444444', 'Neda', 'Karimi');

    $user = makeUserInUnit($ownUnit);

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('personSearch', '4444444444')
        ->assertSet('personResults', []);

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('personSearch', '3333333333')
        ->assertSet('personResults', [
            ['n_code' => '3333333333', 'name' => 'Mohammad Hosseini'],
        ]);
});

it('n_code validation does not leak other units\' person info', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '5555555555', 'Hassan', 'Moradi');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '6666666666', 'Maryam', 'Sadeghi');

    $user = makeUserInUnit($ownUnit);

    // Typing an out-of-scope n_code must NOT reveal name/unit
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('n_code', '6666666666')
        ->assertSet('n_code_status', 'invalid')
        ->assertSet('n_code_name', null)
        ->assertSet('n_code_unit', null);

    // In-scope n_code still validates and shows name/unit
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('n_code', '5555555555')
        ->assertSet('n_code_status', 'valid')
        ->assertSet('n_code_name', 'Hassan Moradi')
        ->assertSet('n_code_unit', 'Unit A');
});

it('createHardware rejects out-of-scope person', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '7777777777', 'Javad', 'Ebrahimi');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '8888888888', 'Zahra', 'Qasemi');

    $user = makeUserInUnit($ownUnit);

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('n_code', '8888888888')
        ->set('pc_name', 'PC-OUT-OF-SCOPE')
        ->call('createHardware')
        ->assertHasErrors('n_code'); // exists:persons rule fails for out-of-scope n_code
});

it('general search matches the full person name (f_name + l_name)', function () {
    [$unit, $person] = makeUnitAndPerson('Unit A', '1234567890', 'Mehdi', 'Asgari');
    $user = makeUserInUnit($unit);

    // Hardware must exist so the row is returned
    \App\Models\Hardware::create([
        'n_code' => '1234567890',
        'pc_name' => 'PC-MEHDI-01',
        'type' => 'pc',
    ]);

    $component = Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('search', 'Mehdi Asgari');

    // The combined name must surface the matching hardware row
    expect($component->html())->toContain('PC-MEHDI-01');
});
