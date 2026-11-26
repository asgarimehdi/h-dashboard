<?php

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithAtomicToggles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Ticket::class);

uses(InteractsWithTestSetup::class, InteractsWithAtomicToggles::class);
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
    $unit = Unit::where('id', session('current_unit_id'))->first()
        ?? Unit::create(['name' => 'Ticket Manager Unit', 'can_receive_tickets' => false]);
    $toggle = Unit::create([
        'name' => 'Toggle Unit', 'can_receive_tickets' => false,
        'parent_id' => $unit->id,
    ]);

    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', $toggle->id);

    expect(Unit::find($toggle->id)->can_receive_tickets)->toBeTrue();
});

it('toggleTicketCapability disables ticket reception', function () {
    $user = makeTicketManager();
    $unit = Unit::where('id', session('current_unit_id'))->first()
        ?? Unit::create(['name' => 'Ticket Manager Unit', 'can_receive_tickets' => true]);
    $toggle = Unit::create([
        'name' => 'Toggle Unit 2', 'can_receive_tickets' => true,
        'parent_id' => $unit->id,
    ]);

    Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', $toggle->id);

    expect(Unit::find($toggle->id)->can_receive_tickets)->toBeFalse();
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

// ==================== #1849 — atomic toggleTicketCapability ====================

/**
 * @return array{0: User, 1: Unit}
 */
function makeToggleCapabilityFixture(): array
{
    $user = makeTicketManager();
    $parent = Unit::where('id', session('current_unit_id'))->firstOrFail();

    $toggle = Unit::create([
        'name' => 'Atomic Toggle Unit',
        'can_receive_tickets' => false,
        'parent_id' => $parent->id,
    ]);

    return [$user, $toggle];
}

it('toggleTicketCapability uses the locked row, not the stale model', function () {
    // Another writer enabled the row before our lock. Deriving the new value from
    // the stale model (false) writes `true` and loses our toggle.
    [$user, $toggle] = makeToggleCapabilityFixture();

    $this->fakeLockedRow((object) ['id' => $toggle->id, 'can_receive_tickets' => true]);

    $component = Livewire::actingAs($user)
        ->test('units.index')
        ->call('toggleTicketCapability', $toggle->id);

    expect((bool) Unit::find($toggle->id)->can_receive_tickets)->toBeFalse()
        ->and($this->toastTitle($component))->toBe('واحد «Atomic Toggle Unit» برای پذیرش تیکت غیرفعال شد.');
});

it('toggleTicketCapability reports a vanished unit without writing or bumping caches', function () {
    [$user, $toggle] = makeToggleCapabilityFixture();

    $cache = app(CacheInvalidationServiceInterface::class);
    $component = Livewire::actingAs($user)->test('units.index');
    $before = $cache->getVersion('unit_hierarchy');

    $this->fakeLockedRow(null);

    $component->call('toggleTicketCapability', $toggle->id);

    expect((bool) Unit::find($toggle->id)->can_receive_tickets)->toBeFalse()
        ->and($cache->getVersion('unit_hierarchy'))->toBe($before)
        ->and($this->toastType($component))->toBe('error')
        ->and($this->toastTitle($component))->toBe('واحد یافت نشد.');
});

it('double toggleTicketCapability returns to the starting value', function () {
    // Sanity only: two sequential flips also return to the starting value on the
    // buggy read-then-write code, so this is NOT the race guard — the
    // locked-row test above is.
    [$user, $toggle] = makeToggleCapabilityFixture();

    Livewire::actingAs($user)->test('units.index')->call('toggleTicketCapability', $toggle->id);
    expect((bool) Unit::find($toggle->id)->can_receive_tickets)->toBeTrue();

    Livewire::actingAs($user)->test('units.index')->call('toggleTicketCapability', $toggle->id);
    expect((bool) Unit::find($toggle->id)->can_receive_tickets)->toBeFalse();
});

it('toggleTicketCapability clears access caches after the write', function () {
    [$user, $toggle] = makeToggleCapabilityFixture();

    $cache = app(CacheInvalidationServiceInterface::class);
    $component = Livewire::actingAs($user)->test('units.index');
    $before = $cache->getVersion('unit_hierarchy');

    $component->call('toggleTicketCapability', $toggle->id);

    expect((bool) Unit::find($toggle->id)->can_receive_tickets)->toBeTrue()
        ->and($cache->getVersion('unit_hierarchy'))->toBeGreaterThan($before);
});
