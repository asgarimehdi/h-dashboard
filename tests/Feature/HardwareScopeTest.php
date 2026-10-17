<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Traits\HasOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

covers(HasOrganizationalScope::class);

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

    // Issue #975: every mutator on `hardware.index` now re-checks
    // `manage_hardware` itself, because the route gate does not re-run on
    // `/livewire/update`. This file exercises those mutators, so its actor has
    // to be someone who LEGITIMATELY holds the permission — otherwise these
    // tests 403 before reaching the scope assertion they exist to make.
    // `findOrCreate` keeps this file independent of PermissionSeeder.
    //
    // Granting the permission does not weaken anything below: scope comes from
    // `accessibleUnitIds()`, which never consults the permission.
    $user->givePermissionTo(Permission::findOrCreate('manage_hardware'));

    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

it('person search only returns persons within the user\'s organizational scope', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '1111111111', 'Ali', 'Rezaei');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '2222222222', 'Sara', 'Ahmadi');

    $user = makeUserInUnit($ownUnit);

    // Issue #1047: assert on what is RENDERED, not on the in-memory
    // `personResults` property — a lazy refactor that keeps the property but
    // still leaks into the view must fail this test.
    //
    // Out-of-scope search: the other unit's person must not appear in the
    // person-search dropdown that the open create form renders.
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('startCreate')
        ->set('personSearch', '2222') // other unit's n_code prefix
        ->assertDontSee('Sara Ahmadi')
        ->assertDontSee('2222222222');

    // In-scope search renders the person as a selectable option — this proves
    // the dropdown path above is genuinely exercised rather than vacuously empty.
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('startCreate')
        ->set('personSearch', 'Ali')
        ->assertSee('Ali Rezaei')
        ->assertSee('1111111111');
});

it('person search by n_code respects scope', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '3333333333', 'Mohammad', 'Hosseini');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '4444444444', 'Neda', 'Karimi');

    $user = makeUserInUnit($ownUnit);

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('startCreate')
        ->set('personSearch', '4444444444')
        ->assertDontSee('Neda Karimi')
        ->assertDontSee('4444444444');

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('startCreate')
        ->set('personSearch', '3333333333')
        ->assertSee('Mohammad Hosseini')
        ->assertSee('3333333333');
});

it('n_code validation does not leak other units\' person info', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '5555555555', 'Hassan', 'Moradi');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '6666666666', 'Maryam', 'Sadeghi');

    $user = makeUserInUnit($ownUnit);

    // Typing an out-of-scope n_code must NOT render that person's name/unit.
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('startCreate')
        ->set('n_code', '6666666666')
        ->assertSee('کد ملی یافت نشد') // rendered "invalid" hint
        ->assertDontSee('Maryam Sadeghi');

    // In-scope n_code still validates and renders the person's name.
    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('startCreate')
        ->set('n_code', '5555555555')
        ->assertSee('Hassan Moradi');
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

    // Issue #1047: the database is the source of truth — no row may be written
    // for the out-of-scope person.
    $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-OUT-OF-SCOPE']);
});

it('general search matches the full person name (f_name + l_name)', function () {
    [$unit, $person] = makeUnitAndPerson('Unit A', '1234567890', 'Mehdi', 'Asgari');
    $user = makeUserInUnit($unit);

    // Hardware must exist so the row is returned
    Hardware::create([
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

it('filterType escapes LIKE wildcards — percent does not match all', function () {
    $unit = Unit::create(['name' => 'Unit A']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
    $sId = DB::table('semats')->insertGetId(['name' => 'S']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'R']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Test', 'l_name' => 'User',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
        'u_id' => $unit->id,
    ]);
    Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-1', 'type' => 'laptop']);
    Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-2', 'type' => 'desktop']);

    $user = makeUserInUnit($unit);

    // Searching for '%' should match only literal '%', not everything
    $component = Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('filterType', '%');

    // Neither laptop nor desktop should match a literal '%'
    expect($component->html())->not->toContain('PC-1');
    expect($component->html())->not->toContain('PC-2');
});

it('filterType escapes LIKE wildcards — underscore does not match single char', function () {
    $unit = Unit::create(['name' => 'Unit A']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
    $sId = DB::table('semats')->insertGetId(['name' => 'S']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'R']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Test', 'l_name' => 'User',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
        'u_id' => $unit->id,
    ]);
    Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-1', 'type' => 'laptop']);

    $user = makeUserInUnit($unit);

    // '_' as filter should not match 'laptop' (single-char wildcard)
    $component = Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('filterType', '_');

    expect($component->html())->not->toContain('PC-1');
});

it('filterOs escapes LIKE wildcards — percent does not match all', function () {
    $unit = Unit::create(['name' => 'Unit A']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
    $sId = DB::table('semats')->insertGetId(['name' => 'S']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'R']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Test', 'l_name' => 'User',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
        'u_id' => $unit->id,
    ]);
    Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-1', 'type' => 'pc', 'os' => 'Windows 11']);
    Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-2', 'type' => 'pc', 'os' => 'Linux']);

    $user = makeUserInUnit($unit);

    $component = Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('filterOs', '%');

    // Should not match all — literal % is escaped
    expect($component->html())->not->toContain('PC-1');
    expect($component->html())->not->toContain('PC-2');
});

it('updateHardware refuses to re-point hardware to a person in an inaccessible unit', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '1111111111', 'Ali', 'Rezaei');
    [$otherUnit, $otherPerson] = makeUnitAndPerson('Unit B', '2222222222', 'Sara', 'Ahmadi');

    $user = makeUserInUnit($ownUnit);

    $hw = Hardware::create([
        'n_code' => '1111111111',
        'pc_name' => 'PC-OWN-UNIT',
        'type' => 'pc',
    ]);

    $component = Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('editHardware', $hw->id)
        ->set('n_code', '2222222222') // person of the inaccessible Unit B
        ->call('updateHardware')
        ->assertHasNoErrors();

    $hw->refresh();
    expect($hw->n_code)->toBe('1111111111');

    // The user gets the Persian message (MaryUI toast), not a bare validation error.
    $toastJs = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->first(fn (string $expression) => str_starts_with($expression, 'toast('));
    $toast = $toastJs ? json_decode(substr($toastJs, 6, -1), true) : null;

    expect($toast['toast']['type'] ?? null)->toBe('error');
    expect($toast['toast']['title'] ?? null)->toBe('شما به این پرسنل دسترسی ندارید.');
});

it('updateHardware allows reassigning hardware to a person within the accessible unit', function () {
    [$ownUnit, $ownPerson] = makeUnitAndPerson('Unit A', '3333333333', 'Ali', 'Rezaei');

    $user = makeUserInUnit($ownUnit); // creates a second person inside Unit A

    $hw = Hardware::create([
        'n_code' => '3333333333',
        'pc_name' => 'PC-OWN-UNIT',
        'type' => 'pc',
    ]);

    Livewire::actingAs($user)
        ->test('hardware.index')
        ->call('editHardware', $hw->id)
        ->set('n_code', $user->n_code) // person of the same accessible Unit A
        ->call('updateHardware')
        ->assertHasNoErrors();

    $hw->refresh();
    expect($hw->n_code)->toBe($user->n_code);
});

it('filterUnit escapes LIKE wildcards — percent does not match all', function () {
    $unit = Unit::create(['name' => 'Test Unit']);
    $tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
    $sId = DB::table('semats')->insertGetId(['name' => 'S']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'R']);
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Test', 'l_name' => 'User',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId,
        'u_id' => $unit->id,
    ]);
    Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-1', 'type' => 'pc']);

    $user = makeUserInUnit($unit);

    $component = Livewire::actingAs($user)
        ->test('hardware.index')
        ->set('filterUnit', '%');

    // '%' is escaped, so it won't match 'Test Unit'
    expect($component->html())->not->toContain('PC-1');
});
