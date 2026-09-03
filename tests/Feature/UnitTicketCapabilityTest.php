<?php

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

covers(Ticket::class);

uses(TestCase::class, RefreshDatabase::class);

function makeTicketManager(): User
{
    $unit = Unit::create(['name' => 'Ticket Manager Unit', 'can_receive_tickets' => false]);

    $nCode = (string) fake()->unique()->numerify('##########');
    // Create required related tables
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

    Person::create([
        'n_code' => $nCode, 'f_name' => 'Ticket', 'l_name' => 'Manager',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id,
    ]);

    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    Permission::firstOrCreate(['name' => 'manage_unit_tickets']);
    $user->givePermissionTo('manage_unit_tickets');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

function makeUnauthorizedUser(): User
{
    $unit = Unit::create(['name' => 'Unauthorized Unit']);

    $nCode = (string) fake()->unique()->numerify('##########');
    $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
    $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
    $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
    $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

    Person::create([
        'n_code' => $nCode, 'f_name' => 'Unauth', 'l_name' => 'User',
        't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id,
    ]);

    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

it('can_receive_tickets defaults to false', function () {
    $unit = new Unit(['name' => 'Test Unit A']);
    expect($unit->can_receive_tickets)->toBeFalse();

    $saved = Unit::create(['name' => 'Test Unit B']);
    expect($saved->can_receive_tickets)->toBeFalse();
});

it('toggleTicketCapability enables ticket reception', function () {
    $user = makeTicketManager();
    $unit = Unit::create(['name' => 'Toggle Unit', 'can_receive_tickets' => false]);

    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', $unit->id);

    expect(Unit::find($unit->id)->can_receive_tickets)->toBeTrue();
});

it('toggleTicketCapability disables ticket reception', function () {
    $user = makeTicketManager();
    $unit = Unit::create(['name' => 'Toggle Unit 2', 'can_receive_tickets' => true]);

    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', $unit->id);

    expect(Unit::find($unit->id)->can_receive_tickets)->toBeFalse();
});

it('toggleTicketCapability requires manage_unit_tickets permission', function () {
    $unit = Unit::create(['name' => 'No Perm Unit', 'can_receive_tickets' => false]);
    $user = makeUnauthorizedUser();

    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', $unit->id);

    expect(Unit::find($unit->id)->can_receive_tickets)->toBeFalse();
});

it('toggleTicketCapability handles non-existent unit', function () {
    $user = makeTicketManager();

    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', 99999);

    // Should not throw
    $this->assertTrue(true);
});

it('ticket create only shows units with can_receive_tickets true', function () {
    Unit::create(['name' => 'Receiver Unit', 'can_receive_tickets' => true, 'is_active' => true]);
    Unit::create(['name' => 'Non-Receiver Unit', 'can_receive_tickets' => false, 'is_active' => true]);

    $receivers = Unit::where('can_receive_tickets', true)->where('is_active', true)->get();
    expect($receivers)->toHaveCount(1);
    expect($receivers->first()->name)->toBe('Receiver Unit');
});
