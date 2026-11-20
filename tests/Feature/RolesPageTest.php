<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
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
});

test('guest is redirected from roles page', function () {
    $this->get('/roles')->assertRedirect('/login');
});

test('roles page returns 403 without permission', function () {
    $this->actingAs($this->user);
    $this->get('/roles')->assertStatus(403);
});

test('roles page loads for authorized user', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('manage_roles');

    Livewire::test('roles/index')->assertStatus(200);
});

/*
 * ---------------------------------------------------------------------------
 * Issue #1607 — the delegation ceiling on roles.index
 * ---------------------------------------------------------------------------
 * `manage_roles` alone must not be able to reach above its own permission
 * ceiling, and the `admin` role (identity AND name) is admin-only. A
 * non-admin actor here holds `manage_roles` and nothing else, so the ceiling
 * is the only thing that can refuse — not the route gate.
 */
test('#1607: a non-admin cannot delete the admin role', function () {
    $this->user->givePermissionTo('manage_roles');
    $adminRole = Role::where('name', 'admin')->firstOrFail();

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->call('delete', $adminRole->id)
        ->assertForbidden();

    $this->assertDatabaseHas('roles', ['id' => $adminRole->id, 'name' => 'admin']);
});

test('#1607: a non-admin cannot rename or re-permission the admin role', function () {
    $this->user->givePermissionTo('manage_roles');
    $adminRole = Role::where('name', 'admin')->firstOrFail();

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->call('editRole', $adminRole->id)
        ->set('name', 'adminrenamed')
        ->set('label', 'مدیر تغییرنام‌یافته')
        ->set('permissions', ['manage_roles'])
        ->call('updateRole')
        ->assertForbidden();

    expect($adminRole->fresh()->name)->toBe('admin');
    expect($adminRole->fresh()->label)->not->toBe('مدیر تغییرنام‌یافته');
});

test('#1607: a non-admin cannot add a permission it does not hold to an existing role', function () {
    // Probe 6 of the issue: `manage_roles`-only, editing a role it already
    // carries, was enough to reach every permission without touching `admin`.
    $this->user->givePermissionTo('manage_roles');
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $this->user->assignRole($role);

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->call('editRole', $role->id)
        ->set('permissions', ['manage_diseases'])
        ->call('updateRole')
        ->assertForbidden();

    expect($role->fresh()->permissions)->toBeEmpty();
});

test('#1607: a non-admin cannot mint a role carrying permissions it does not hold', function () {
    $this->user->givePermissionTo('manage_roles');

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->set('name', 'allpowerful')
        ->set('label', 'همه کاره')
        ->set('permissions', ['manage_diseases', 'manage_users', 'manage_roles'])
        ->call('createRole')
        ->assertForbidden();

    $this->assertDatabaseMissing('roles', ['name' => 'allpowerful']);
});

test('#1607: a non-admin can create a role whose permissions it holds', function () {
    // Anti-over-blocking: delegation itself must keep working.
    $this->user->givePermissionTo('manage_roles');

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->set('name', 'delegated')
        ->set('label', 'تفویضی')
        ->set('permissions', ['manage_roles'])
        ->call('createRole')
        ->assertHasNoErrors();

    $created = Role::where('name', 'delegated')->first();
    expect($created)->not->toBeNull();
    expect($created->permissions->pluck('name')->all())->toBe(['manage_roles']);
});

test('#1607: a non-admin cannot create a role named admin', function () {
    // With the admin role gone, a role named `admin` would pick up the
    // `role:admin` route access (backup) that no permission represents.
    Role::where('name', 'admin')->firstOrFail()->delete();
    $this->user->givePermissionTo('manage_roles');

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->set('name', 'admin')
        ->set('label', 'مدیر جعلی')
        ->set('permissions', ['manage_roles'])
        ->call('createRole')
        ->assertForbidden();

    expect(Role::where('name', 'admin')->exists())->toBeFalse();
});

test('#1607: a non-admin cannot rename a role to admin', function () {
    Role::where('name', 'admin')->firstOrFail()->delete();
    $this->user->givePermissionTo('manage_roles');
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $this->user->assignRole($role);

    Livewire::actingAs($this->user)
        ->test('roles/index')
        ->call('editRole', $role->id)
        ->set('name', 'admin')
        ->set('permissions', ['manage_roles'])
        ->call('updateRole')
        ->assertForbidden();

    expect($role->fresh()->name)->toBe('operator');
});

test('#1607: an admin-role holder can create, rename and delete a role', function () {
    // The bypass is on the ROLE, not the permission: the seeded `admin` role
    // does not carry `manage_roles`, so both are granted here.
    $this->user->givePermissionTo('manage_roles');
    $this->user->assignRole('admin');

    $component = Livewire::actingAs($this->user)->test('roles/index');

    $component
        ->set('name', 'auditor')
        ->set('label', 'بازرس')
        ->set('permissions', ['manage_diseases', 'manage_users'])
        ->call('createRole')
        ->assertHasNoErrors();

    $created = Role::where('name', 'auditor')->first();
    expect($created)->not->toBeNull();

    $component
        ->call('editRole', $created->id)
        ->set('name', 'auditorrenamed')
        ->set('label', 'بازرس تغییرنام‌یافته')
        ->set('permissions', ['manage_diseases', 'manage_users'])
        ->call('updateRole')
        ->assertHasNoErrors();

    expect($created->fresh()->name)->toBe('auditorrenamed');

    $component->call('delete', $created->id);

    $this->assertDatabaseMissing('roles', ['id' => $created->id]);
});
