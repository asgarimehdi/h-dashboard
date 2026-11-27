<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Unit::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->seedLookupTables();

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);

    // Membership (`user_units`) is the sole source of scope (#1830, D1=B).
    // The fixture must obtain its unit the same way a real account does.
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
});

test('guest is redirected from select-context', function () {
    $this->get('/select-context')->assertRedirect('/login');
});

test('select-context redirects to dashboard for single unit', function () {
    $this->actingAs($this->user);
    $this->get('/select-context')->assertRedirect('/dashboard');
});

test('select-context loads when multiple units exist', function () {
    $secondUnit = Unit::create(['name' => 'واحد دوم']);
    $this->user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

    $this->actingAs($this->user);
    Livewire::test('select-context')->assertStatus(200);
});

/**
 * Issue #1830 (owner decision D1=B) — membership (`user_units`) is the sole
 * source of scope; a person's `u_id` is an HR field, never an access grant.
 *
 * The picker is the redirect target for every unitless account, so it must
 * NOT re-select the person's HR unit: doing so both resurrects the revoked
 * unit in the session and bounces the browser between `/select-context` and
 * `/dashboard` forever.
 */
test('select-context does not resurrect the person unit for a memberless user', function () {
    $this->actingAs($this->user);

    // The person row still points at the unit (HR data), but the membership
    // was revoked — there is no `user_units` row at all.
    $this->user->units()->detach();

    $response = $this->get('/select-context');

    $response->assertStatus(200);
    expect(session('current_unit_id'))->toBeNull();
    expect(session('current_unit_name'))->toBeNull();
});

test('a memberless user reaches the picker and is not caught in a redirect loop', function () {
    $this->actingAs($this->user);
    $this->user->units()->detach();

    // First hop: the gated page sends the unitless account to the picker.
    $this->get('/dashboard')->assertRedirect('/select-context');

    // The picker is reachable and stable — it neither selects a unit nor
    // bounces back to the gated page.
    $this->get('/select-context')->assertStatus(200);

    // And the gated page still sends it back to the (now stable) picker.
    $this->get('/dashboard')->assertRedirect('/select-context');
});
