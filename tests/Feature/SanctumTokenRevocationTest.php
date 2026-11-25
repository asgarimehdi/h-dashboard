<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithApiTokens::class);

/**
 * Issue #1823 — a Sanctum token snapshots its abilities at login, so a
 * role/permission edit must revoke the holder's tokens or the API keeps
 * enforcing abilities the holder no longer has. Any ability-set change
 * revokes (grant or revoke); a privilege-neutral edit revokes nothing.
 */
function sanctumRevocationUser(Unit $unit, string $nCode): User
{
    Person::factory()->create(['n_code' => $nCode, 'u_id' => $unit->id]);

    $user = User::factory()->create(['n_code' => $nCode]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    return $user;
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    $this->unit = Unit::factory()->create();

    $this->admin = sanctumRevocationUser($this->unit, '7000000001');
    // `manage_hardware` is part of the actor's ceiling so role edits that
    // remove it are never refused as an amplification.
    $this->admin->givePermissionTo(['manage_users', 'manage_roles', 'manage_hardware']);

    Session::put('current_unit_id', $this->unit->id);
});

test('#1823: removing a direct permission revokes the holder\'s token (401, not a stale ability)', function () {
    $bareRole = Role::create(['name' => 'barerole', 'label' => 'بدون دسترسی']);

    $target = sanctumRevocationUser($this->unit, '7000000002');
    $target->assignRole($bareRole);
    $target->givePermissionTo('manage_hardware');

    $token = $this->createApiToken($target, $target->getApiTokenAbilities());
    $this->apiGet('/api/hardware', $token)->assertOk();

    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$bareRole->id])
        ->set('user_permissions', [])
        ->call('updateUser')
        ->assertHasNoErrors();

    expect($target->fresh()->hasPermissionTo('manage_hardware'))->toBeFalse();
    expect($target->tokens()->count())->toBe(0);

    // The row is gone, so the old Bearer token is unauthenticated (401) —
    // NOT the 403 the live Spatie gate would return even without the fix.
    $this->apiGet('/api/hardware', $token)->assertUnauthorized();
});

test('#1823: a role-based demotion revokes tokens even though hasRole() cached the old roles', function () {
    $hardwareRole = Role::create(['name' => 'hardwarerole', 'label' => 'سخت‌افزار']);
    $hardwareRole->givePermissionTo('manage_hardware');
    $bareRole = Role::create(['name' => 'barerole', 'label' => 'بدون دسترسی']);

    $target = sanctumRevocationUser($this->unit, '7000000003');
    $target->assignRole($hardwareRole);

    $token = $this->createApiToken($target, $target->getApiTokenAbilities());

    // The "after" snapshot is computed on the same instance whose `roles`
    // relation `hasRole('admin')` loaded at the top of updateUser(); without
    // `unsetRelation('roles')` this test fails and the demotion is a no-op.
    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$bareRole->id])
        ->call('updateUser')
        ->assertHasNoErrors();

    expect($target->fresh()->hasRole('hardwarerole'))->toBeFalse();
    expect($target->tokens()->count())->toBe(0);

    $this->apiGet('/api/hardware', $token)->assertUnauthorized();
});

test('#1823: a privilege-neutral user edit keeps the token alive', function () {
    $role = Role::create(['name' => 'hardwarerole', 'label' => 'سخت‌افزار']);
    $role->givePermissionTo('manage_hardware');

    $target = sanctumRevocationUser($this->unit, '7000000004');
    $target->assignRole($role);

    $token = $this->createApiToken($target, $target->getApiTokenAbilities());

    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->call('updateUser')
        ->assertHasNoErrors();

    expect($target->tokens()->count())->toBe(1);
    $this->apiGet('/api/hardware', $token)->assertOk();
});

test('#1823: a role permission change revokes every holder, and only the holders', function () {
    $role = Role::create(['name' => 'hardwarerole', 'label' => 'سخت‌افزار']);
    $role->givePermissionTo(['manage_hardware', 'manage_roles']);

    $holderA = sanctumRevocationUser($this->unit, '7000000005');
    $holderB = sanctumRevocationUser($this->unit, '7000000006');
    $holderA->assignRole($role);
    $holderB->assignRole($role);

    // Same ability, no role — proves the fan-out is scoped to this role.
    $outsider = sanctumRevocationUser($this->unit, '7000000007');
    $outsider->givePermissionTo('manage_hardware');

    $tokenA = $this->createApiToken($holderA, $holderA->getApiTokenAbilities());
    $tokenB = $this->createApiToken($holderB, $holderB->getApiTokenAbilities());
    $outsiderToken = $this->createApiToken($outsider, $outsider->getApiTokenAbilities());

    Livewire::actingAs($this->admin)->test('roles/index')
        ->call('editRole', $role->id)
        ->set('permissions', ['manage_roles'])
        ->call('updateRole')
        ->assertHasNoErrors();

    expect($holderA->tokens()->count())->toBe(0)
        ->and($holderB->tokens()->count())->toBe(0)
        ->and($outsider->tokens()->count())->toBe(1);

    $this->apiGet('/api/hardware', $tokenA)->assertUnauthorized();
    $this->apiGet('/api/hardware', $tokenB)->assertUnauthorized();
    $this->apiGet('/api/hardware', $outsiderToken)->assertOk();
});

test('#1823: a rename-only role edit keeps the holders\' tokens alive', function () {
    $role = Role::create(['name' => 'hardwarerole', 'label' => 'سخت‌افزار']);
    $role->givePermissionTo('manage_hardware');

    $holder = sanctumRevocationUser($this->unit, '7000000008');
    $holder->assignRole($role);

    $token = $this->createApiToken($holder, $holder->getApiTokenAbilities());

    Livewire::actingAs($this->admin)->test('roles/index')
        ->call('editRole', $role->id)
        ->set('name', 'renamedrole')
        ->set('label', 'نام تازه')
        ->call('updateRole')
        ->assertHasNoErrors();

    expect($role->fresh()->name)->toBe('renamedrole');
    expect($holder->tokens()->count())->toBe(1);

    $this->apiGet('/api/hardware', $token)->assertOk();
});

test('#1823: deleting a role revokes its holders\' tokens', function () {
    $role = Role::create(['name' => 'hardwarerole', 'label' => 'سخت‌افزار']);
    $role->givePermissionTo('manage_hardware');

    $holder = sanctumRevocationUser($this->unit, '7000000009');
    $holder->assignRole($role);

    $token = $this->createApiToken($holder, $holder->getApiTokenAbilities());

    Livewire::actingAs($this->admin)->test('roles/index')
        ->call('delete', $role->id);

    expect(Role::find($role->id))->toBeNull();
    expect($holder->tokens()->count())->toBe(0);

    $this->apiGet('/api/hardware', $token)->assertUnauthorized();
});
