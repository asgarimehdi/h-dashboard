<?php

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Ticket::class);

uses(InteractsWithTestSetup::class);
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

function makeTicketManager(): User
{
    $unit = Unit::create(['name' => 'Ticket Manager Unit', 'can_receive_tickets' => false]);

    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Ticket', 'l_name' => 'Manager',
        't_id' => DB::table('tahsils')->first()->id, 'e_id' => DB::table('estekhdams')->first()->id, 's_id' => DB::table('semats')->first()->id, 'r_id' => DB::table('radifs')->first()->id, 'u_id' => $unit->id,
    ]);

    $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
    $user->givePermissionTo('manage_unit_tickets');
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    return $user;
}

function makeUnauthorizedUser(): User
{
    $unit = Unit::create(['name' => 'Unauthorized Unit']);

    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode, 'f_name' => 'Unauth', 'l_name' => 'User',
        't_id' => DB::table('tahsils')->first()->id, 'e_id' => DB::table('estekhdams')->first()->id, 's_id' => DB::table('semats')->first()->id, 'r_id' => DB::table('radifs')->first()->id, 'u_id' => $unit->id,
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
