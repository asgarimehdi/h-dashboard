<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

covers(User::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

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

    $this->admin = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->admin->givePermissionTo('manage_users');
    $this->admin->givePermissionTo('manage_roles');

    Session::put('current_unit_id', $this->unit->id);
});

test('users index renders and filters results', function () {
    // Create persons for the users first (FK constraint).
    // Note: the signed-in admin is excluded from the list by the component.
    Person::create([
        'n_code' => '1112223334',
        'f_name' => 'کاربر',
        'l_name' => 'دیگر',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $otherUser = User::factory()->create(['n_code' => '1112223334']);

    Person::create([
        'n_code' => '2223334445',
        'f_name' => 'کاربر',
        'l_name' => 'دوم',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $secondUser = User::factory()->create(['n_code' => '2223334445']);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->assertOk()
        ->assertSee('کاربر دیگر')
        ->assertSee('کاربر دوم');

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->set('search', 'کاربر دوم')
        ->assertSee('کاربر دوم')
        ->assertDontSee('کاربر دیگر');
});

test('create user persists data and assigns roles', function () {
    // Create a person first (FK constraint)
    $person = Person::create([
        'n_code' => '9998887776',
        'f_name' => 'کاربر',
        'l_name' => 'جدید',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9998887776')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->call('createUser');

    $this->assertDatabaseHas('users', ['n_code' => '9998887776']);
    $this->assertTrue(User::where('n_code', '9998887776')->first()->hasRole('operator'));
});

test('change password updates password correctly', function () {
    $user = $this->admin;
    $oldPassword = 'old-password';
    $newPassword = 'new-password-123';

    // Manually set password for test
    $user->update(['password' => bcrypt($oldPassword)]);

    Livewire::actingAs($this->admin)
        ->test('auth.changepassword')
        ->set('currentPassword', $oldPassword)
        ->set('newPassword', $newPassword)
        ->set('newPasswordConfirmation', $newPassword)
        ->call('changePassword');

    $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
});

test('roles and permissions pages render and allow modifications', function () {
    Livewire::actingAs($this->admin)
        ->test('roles.index')
        ->assertOk();

    Livewire::actingAs($this->admin)
        ->test('permissions.index')
        ->assertOk();
});

test('users management pages are protected by RBAC', function () {
    $noPermUser = User::factory()->create();

    $urls = ['/users', '/roles', '/permissions'];

    foreach ($urls as $url) {
        $this->actingAs($noPermUser)
            ->get($url)
            ->assertStatus(403);
    }
});

test('non-admin with manage_users cannot assign roles without manage_roles', function () {
    $operator = User::factory()->create();
    $operator->givePermissionTo('manage_users');

    $targetPerson = Person::create([
        'n_code' => '1111111111',
        'f_name' => 'هدف',
        'l_name' => 'تست',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $targetUser = User::create(['n_code' => '1111111111', 'password' => bcrypt('password')]);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    $response = Livewire::actingAs($operator)
        ->test('users.index')
        ->call('edit', $targetUser->id)
        ->set('role_ids', [$role->id])
        ->call('updateUser');

    $response->assertForbidden();
});

test('manage_users holder cannot delete themselves', function () {
    $operator = User::factory()->create();
    $operator->givePermissionTo('manage_users');

    $response = Livewire::actingAs($operator)
        ->test('users.index')
        ->call('delete', $operator->id);

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $operator->id, 'deleted_at' => null]);
});

test('non-admin with manage_users cannot edit admin user', function () {
    $adminPerson = Person::create([
        'n_code' => '2222222222',
        'f_name' => 'مدیر',
        'l_name' => 'کل',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $adminUser = User::create(['n_code' => '2222222222', 'password' => bcrypt('password')]);
    $adminUser->assignRole('admin');

    $operator = User::factory()->create();
    $operator->givePermissionTo('manage_users');

    $response = Livewire::actingAs($operator)
        ->test('users.index')
        ->call('edit', $adminUser->id)
        ->call('updateUser');

    $response->assertForbidden();
});

test('admin can still create update delete normally', function () {
    $person = Person::create([
        'n_code' => '3333333333',
        'f_name' => 'کاربر',
        'l_name' => 'جدید',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '3333333333')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->call('createUser');

    $createdUser = User::where('n_code', '3333333333')->first();
    $this->assertNotNull($createdUser);
    $this->assertTrue($createdUser->hasRole('operator'));

    $targetPerson = Person::create([
        'n_code' => '4444444444',
        'f_name' => 'کاربر',
        'l_name' => 'ویرایش',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $targetUser = User::create(['n_code' => '4444444444', 'password' => bcrypt('password')]);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->call('edit', $targetUser->id)
        ->set('role_ids', [$role->id])
        ->call('updateUser');

    $this->assertTrue($targetUser->fresh()->hasRole('operator'));

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->call('delete', $targetUser->id);

    $this->assertSoftDeleted($targetUser);
});

test('users search folds zwj in stored person name', function () {
    $nCode = (string) fake()->unique()->numerify('##########');
    $person = Person::create([
        'n_code' => $nCode,
        'f_name' => 'مهدی',
        'l_name' => "حرفه\u{200C}ای", // ZWNJ in last name — normalized to space on save
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $person->refresh();

    User::factory()->create(['n_code' => $nCode]);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->set('search', 'حرفه ای')
        ->assertViewHas('users', fn ($users) => $users->count() === 1);
});

test('users search folds arabic alef in stored person name', function () {
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'آموزش', // Arabic alef-madda — normalized to plain alef on save
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    User::factory()->create(['n_code' => $nCode]);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->set('search', 'اموزش')
        ->assertSee('اموزش کاربر'); // Alef normalized on save
});

test('users search folds persian digits in stored person name', function () {
    $nCode = (string) fake()->unique()->numerify('##########');
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'تست۴۵', // Persian digits — normalized to Latin on save
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    User::factory()->create(['n_code' => $nCode]);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->set('search', 'تست45')
        ->assertSee('تست45 کاربر'); // Digits normalized on save
});

/*
 * ---------------------------------------------------------------------------
 * Issue #849 — unit scope on users.index
 * ---------------------------------------------------------------------------
 * Each helper call builds its OWN two units so the session context is
 * explicit: the attacker sits in unit A, the victim in unit B, and
 * `current_unit_id` = A. `$attackerPermissions` is a parameter because a
 * permission gate (`manage_roles`) would otherwise be the thing answering
 * with a 403 — when the assertion must be about the unit scope alone, the
 * attacker is given that permission too, so only the scope check can fail.
 *
 * Global function name is prefixed: Pest test files share one process.
 */
function users849Fixtures(array $attackerPermissions = ['manage_users']): array
{
    $unitA = Unit::create(['name' => 'واحد الف']);
    $unitB = Unit::create(['name' => 'واحد ب']);
    Session::put('current_unit_id', $unitA->id);

    $attackerPerson = Person::create([
        'n_code' => '9011111111',
        'f_name' => 'مهاجم',
        'l_name' => 'واحد الف',
        'u_id' => $unitA->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $attacker = User::create(['n_code' => $attackerPerson->n_code, 'password' => bcrypt('secret-1234')]);
    $attacker->givePermissionTo($attackerPermissions);
    $attacker->units()->attach($unitA->id, ['role' => 'staff', 'is_primary' => true]);

    $victimPerson = Person::create([
        'n_code' => '9022222222',
        'f_name' => 'قربانی',
        'l_name' => 'واحد ب',
        'u_id' => $unitB->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $victim = User::create(['n_code' => $victimPerson->n_code, 'password' => bcrypt('secret-1234')]);
    $victim->units()->attach($unitB->id, ['role' => 'staff', 'is_primary' => true]);

    return ['unitA' => $unitA, 'unitB' => $unitB, 'attacker' => $attacker, 'victim' => $victim];
}

test('#849: user table, unit picker and person search are unit-scoped', function () {
    $f = users849Fixtures();

    // A colleague (a real account) in the attacker's own unit — proves the
    // queries are SCOPED, not emptied.
    Person::create([
        'n_code' => '9033333333',
        'f_name' => 'همکار',
        'l_name' => 'واحد الف',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    User::create(['n_code' => '9033333333', 'password' => bcrypt('secret-1234')]);

    $component = Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->assertOk();

    // Unit picker (mount): only the actor's own unit is offered.
    expect(collect($component->get('allUnits'))->pluck('id')->all())->toBe([$f['unitA']->id]);
    expect(collect($component->get('allUnitsTree'))->pluck('id')->all())->toBe([$f['unitA']->id]);

    // Table: the unit-B victim is absent, the unit-A colleague is present.
    $component->assertViewHas('users', function ($users) use ($f) {
        $nCodes = $users->pluck('n_code');

        return ! $nCodes->contains($f['victim']->n_code) && $nCodes->contains('9033333333');
    });

    // Person search: an out-of-scope term resolves nobody, an in-scope one
    // still resolves.
    $component->call('openFormForCreate')
        ->set('person_search', 'قربانی')
        ->assertViewHas('persons', fn ($persons) => $persons === []);

    $component->set('person_search', 'همکار')
        ->assertViewHas('persons', fn ($persons) => count($persons) === 1 && $persons[0]['value'] === '9033333333');
});

test('#849: an empty scope renders nobody, not everybody', function () {
    $f = users849Fixtures();

    // Same actor, same rows — ONLY the resolved scope differs. `[]` means
    // "in scope of nothing", never "unrestricted" (AGENTS.md), so every
    // surface must go empty rather than dropping the filter.
    $this->mock(AccessService::class, function ($mock) {
        $mock->shouldReceive('accessibleUnitIds')->andReturn([]);
    });

    $component = Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->assertOk()
        ->assertViewHas('users', fn ($users) => $users->count() === 0);

    expect($component->get('allUnits'))->toBe([]);
    expect($component->get('allUnitsTree'))->toBe([]);

    $component->call('openFormForCreate')
        ->set('person_search', 'مهاجم')
        ->assertViewHas('persons', fn ($persons) => $persons === []);
});

test('#849: selectPerson refuses an out-of-scope person', function () {
    $f = users849Fixtures();

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('selectPerson', $f['victim']->n_code)
        ->assertForbidden();

    // In-scope person still resolves.
    $component = Livewire::actingAs($f['attacker'])->test('users.index');
    $component->call('selectPerson', $f['attacker']->n_code);
    expect($component->get('n_code'))->toBe($f['attacker']->n_code);
});

test('#849: edit() refuses a foreign user', function () {
    $f = users849Fixtures();

    // The 403 fires BEFORE any of the victim's state is written: on a
    // non-2xx Livewire response the harness exposes no component state at
    // all, so the only observable contract is the refusal itself — the
    // direct-state test below pins the write side.
    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('edit', $f['victim']->id)
        ->assertForbidden();
});

test('#849: the direct editing_user_id path cannot take over a foreign user', function () {
    // manage_roles is granted ON PURPOSE: the empty/permission gate must not
    // be what answers here, only the unit scope.
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->set('editing_user_id', $f['victim']->id)
        ->set('n_code', $f['victim']->n_code)
        ->set('password', 'hijacked-99')
        ->set('role_ids', [$role->id])
        ->set('user_permissions', ['manage_users'])
        // Only the attacker's OWN unit — sync() is a full replace, so this
        // is exactly the re-parenting vector the issue proved.
        ->set('unit_ids', [$f['unitA']->id])
        ->call('updateUser')
        ->assertForbidden();

    $f['victim']->refresh();

    expect($f['victim']->units()->pluck('units.id')->all())->toBe([$f['unitB']->id]);
    expect(Hash::check('hijacked-99', $f['victim']->password))->toBeFalse();
    expect($f['victim']->hasRole('operator'))->toBeFalse();
    expect($f['victim']->hasPermissionTo('manage_users'))->toBeFalse();
});

test('#849: delete and restore are refused for a foreign user', function () {
    $f = users849Fixtures();
    $victimId = $f['victim']->id;

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('delete', $victimId)
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $victimId, 'deleted_at' => null]);

    $f['victim']->delete();

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('restore', $victimId)
        ->assertForbidden();

    expect($f['victim']->fresh()->trashed())->toBeTrue();
});

test('#849: createUser refuses units outside the actor scope', function () {
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    Person::create([
        'n_code' => '9044444444',
        'f_name' => 'جدید',
        'l_name' => 'کاربر',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9044444444')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [$f['unitB']->id])
        ->call('createUser')
        ->assertForbidden();

    expect(User::where('n_code', '9044444444')->exists())->toBeFalse();
});

test('#849: createUser requires at least one role, like updateUser', function () {
    $f = users849Fixtures();

    Person::create([
        'n_code' => '9055555555',
        'f_name' => 'بدون',
        'l_name' => 'نقش',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9055555555')
        ->set('password', 'password123')
        ->set('role_ids', [])
        ->call('createUser')
        ->assertHasErrors(['role_ids']);

    expect(User::where('n_code', '9055555555')->exists())->toBeFalse();
});

test('#849: in-scope users can still be edited, assigned and deleted', function () {
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    Person::create([
        'n_code' => '9066666666',
        'f_name' => 'همکار',
        'l_name' => 'واحد الف',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $colleague = User::create(['n_code' => '9066666666', 'password' => bcrypt('secret-1234')]);
    $colleague->units()->attach($f['unitA']->id, ['role' => 'staff', 'is_primary' => true]);

    $component = Livewire::actingAs($f['attacker'])->test('users.index');
    $component->call('edit', $colleague->id)->assertSuccessful();
    expect($component->get('unit_ids'))->toBe([$f['unitA']->id]);

    $component->set('role_ids', [$role->id])
        ->call('updateUser')
        ->assertHasNoErrors();

    expect($colleague->fresh()->hasRole('operator'))->toBeTrue();

    $component->call('delete', $colleague->id);
    expect($colleague->fresh()->trashed())->toBeTrue();
});
