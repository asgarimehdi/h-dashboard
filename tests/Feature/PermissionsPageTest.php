<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

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
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
});

test('guest is redirected from permissions page', function () {
    $this->get('/permissions')->assertRedirect('/login');
});

test('permissions page returns 403 without permission', function () {
    $this->actingAs($this->user);
    $this->get('/permissions')->assertStatus(403);
});

test('permissions page loads for authorized user', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage_roles');

    Livewire::test('permissions/index')->assertStatus(200);
});

// ======================================================================
// #1921 — delegation ceiling on permissions.index
// ======================================================================

test('#1921: a non-admin cannot delete an assigned permission', function () {
    ['user' => $actor] = $this->createUserWithUnit(['manage_roles']);

    // Assign it to a role so the delete would strip a real holder.
    Role::create(['name' => 'holderrole', 'label' => 'دارنده'])->givePermissionTo('organization');
    $permission = Permission::findByName('organization');

    Livewire::actingAs($actor)->test('permissions/index')
        ->call('delete', $permission->id)
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});

test('#1921: a non-admin cannot delete a directly-assigned permission', function () {
    $permission = Permission::create(['name' => 'direct_only', 'label' => 'فقط مستقیم']);

    ['user' => $target] = $this->createUserWithUnit([]);
    $target->givePermissionTo('direct_only');

    ['user' => $actor] = $this->createUserWithUnit(['manage_roles']);

    Livewire::actingAs($actor)->test('permissions/index')
        ->call('delete', $permission->id)
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});

test('#1921: a non-admin can delete an unassigned permission', function () {
    $permission = Permission::create(['name' => 'unused_perm', 'label' => 'بدون تخصیص']);

    ['user' => $actor] = $this->createUserWithUnit(['manage_roles']);

    Livewire::actingAs($actor)->test('permissions/index')
        ->call('delete', $permission->id);

    $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
});

test('#1921: a non-admin cannot rename a permission it does not hold', function () {
    ['user' => $actor] = $this->createUserWithUnit(['manage_roles', 'calendar']);
    $permission = Permission::findByName('manage_hardware');

    Livewire::actingAs($actor)->test('permissions/index')
        ->call('editPermission', $permission->id)
        ->set('name', 'hardwarehijacked')
        ->call('updatePermission')
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', [
        'id' => $permission->id,
        'name' => 'manage_hardware',
    ]);
});

test('#1921: a non-admin cannot rename its own permission into a gate it does not hold', function () {
    ['user' => $actor] = $this->createUserWithUnit(['manage_roles', 'calendar']);

    // Simulate the freed name: the pivot is keyed by permission id, so deleting
    // the seeded `organization` row directly is what the attack does first.
    Permission::findByName('organization')->delete();

    $calendar = Permission::findByName('calendar');

    Livewire::actingAs($actor)->test('permissions/index')
        ->call('editPermission', $calendar->id)
        ->set('name', 'organization')
        ->call('updatePermission')
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', ['id' => $calendar->id, 'name' => 'calendar']);
    $this->assertDatabaseMissing('permissions', ['name' => 'organization']);
});

test('#1921: an admin can delete and rename a non-critical permission', function () {
    ['user' => $admin] = $this->createUserWithUnit(['manage_roles'], 'admin');

    $calendar = Permission::findByName('calendar');

    Livewire::actingAs($admin)->test('permissions/index')
        ->call('editPermission', $calendar->id)
        ->set('name', 'calendarrenamed')
        ->call('updatePermission')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('permissions', ['id' => $calendar->id, 'name' => 'calendarrenamed']);

    $organization = Permission::findByName('organization');

    Livewire::actingAs($admin)->test('permissions/index')
        ->call('delete', $organization->id);

    $this->assertDatabaseMissing('permissions', ['id' => $organization->id]);
});

test('#1921: no one can delete or rename the manage_roles gate', function () {
    ['user' => $admin] = $this->createUserWithUnit(['manage_roles'], 'admin');
    $gate = Permission::findByName('manage_roles');

    Livewire::actingAs($admin)->test('permissions/index')
        ->call('delete', $gate->id)
        ->assertForbidden();

    Livewire::actingAs($admin)->test('permissions/index')
        ->call('editPermission', $gate->id)
        ->set('name', 'managerolesrenamed')
        ->set('label', 'برچسب تازه')
        ->call('updatePermission')
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', ['id' => $gate->id, 'name' => 'manage_roles']);
});
