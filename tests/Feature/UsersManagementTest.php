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
use ReflectionMethod;
use RuntimeException;
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

/**
 * Issue #1030: a target account in the acting admin's unit scope, ready to be
 * edited through users.index. Mirrors the #849 fixtures' person+user+unit shape.
 */
function users1030Target(Unit $unit, string $nCode): User
{
    Person::create([
        'n_code' => $nCode,
        'f_name' => 'هدف',
        'l_name' => 'تست',
        'u_id' => $unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    return $user;
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

    // #1171: the picker is render-on-open — while the modal is closed the tree
    // is not in the DOM at all. A scope assertion against a closed modal would
    // be vacuously true, so the modal is opened first and BOTH the positive and
    // the negative assertion run against the tree that is really rendered.
    // (`flat:` is the picker's inline unit JSON — the only thing that emits it.
    // A bare `toggle(` is NOT a safe probe: MaryUI's theme switcher renders
    // `@click="toggle()"` too.)
    $component->assertDontSeeHtml('flat:')
        ->assertDontSeeHtml('@click="toggle('.$f['unitA']->id.')"');

    // Unit picker (#1040: `allUnits`/`allUnitsTree` are `#[Computed]` now and
    // no longer in the snapshot, so the SAME scope contract is locked through
    // the rendered HTML instead of reading the property). The tree renders a
    // node per accessible unit — exactly one node, the actor's own.
    $component->set('unitModal', true)
        ->assertSeeHtml('@click="toggle('.$f['unitA']->id.')"')
        ->assertDontSeeHtml('@click="toggle('.$f['unitB']->id.')"');

    // The selectedUnitNames chips resolve through the same computed
    // `allUnits` (they only render with the form OPEN): an own-unit
    // selection renders its name as a selected chip, a foreign one renders
    // nothing — the picker's WRITE-target list is still scoped.
    // `openFormForCreate()` resets the form, so selections are set after it.
    $component->call('openFormForCreate')
        ->set('unit_ids', [$f['unitA']->id])
        ->assertSeeHtml('badge badge-primary">'.$f['unitA']->name.'</span>')
        ->set('unit_ids', [$f['unitB']->id])
        ->assertSee('واحدی انتخاب نشده')
        ->assertDontSeeHtml('badge badge-primary">'.$f['unitB']->name.'</span>');

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

    // The picker renders not a single node — [] is "in scope of nothing",
    // never "whole org" (fail-closed), verified through the rendered tree.
    // #1171: the tree only exists while its modal is open, so the modal is
    // opened first — on a closed modal the assertion would be vacuously true.
    $component->set('unitModal', true)
        ->assertDontSeeHtml('@click="toggle('.$f['unitA']->id.')"')
        ->assertDontSeeHtml('@click="toggle('.$f['unitB']->id.')"');

    $component->call('openFormForCreate')
        ->set('person_search', 'مهاجم')
        ->assertViewHas('persons', fn ($persons) => $persons === []);
});

/**
 * #1171: the unit tree is built only while its modal is open.
 *
 * The picker assembles the whole org tree in PHP and serialises every unit
 * into inline JSON — ≈494 KB of HTML for one render on the dev database. It
 * is now included inside `@if ($unitModal)`, so a closed page carries no tree
 * at all and the cost moves to the moment the modal opens.
 *
 * The scope contract is NOT what this test is about: it is pinned by the #849
 * test above. This one pins the render contract — closed means no tree.
 */
test('#1171: the unit tree renders only while the modal is open', function () {
    $f = users849Fixtures();

    $component = Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->assertOk();

    // Closed: no tree node and no inline unit JSON either.
    $component->assertDontSeeHtml('flat:')
        ->assertDontSeeHtml('@click="toggle('.$f['unitA']->id.')"');

    // Open: the tree arrives, and it is scoped exactly as before.
    $component->set('unitModal', true)
        ->assertSeeHtml('flat:')
        ->assertSeeHtml('@click="toggle('.$f['unitA']->id.')"')
        ->assertDontSeeHtml('@click="toggle('.$f['unitB']->id.')"');

    // Closed again: the tree is dropped again.
    $component->set('unitModal', false)
        ->assertDontSeeHtml('flat:')
        ->assertDontSeeHtml('@click="toggle('.$f['unitA']->id.')"');
});

/**
 * #1171: the closed path must stay cheap even with a large org tree.
 *
 * A synthetic 200-unit hierarchy stands in for the dev database's 834 rows.
 * The point of render-on-open is that the cost of the tree is paid when the
 * modal opens — not on every render — so the closed page must not grow at all
 * when the organization grows.
 */
test('#1171: a 200-unit tree does not reach the closed page', function () {
    $root = Unit::create(['name' => 'ریشه سازمانی']);
    Session::put('current_unit_id', $root->id);

    // Baseline: the closed page with ONE unit in scope.
    $before = strlen(Livewire::actingAs($this->admin)->test('users.index')->html());

    // Root + 199 descendants: the whole synthetic hierarchy is in scope.
    for ($i = 1; $i < 200; $i++) {
        Unit::create(['name' => 'واحد '.$i, 'parent_id' => $root->id]);
    }

    $component = Livewire::actingAs($this->admin)
        ->test('users.index')
        ->assertOk();

    $closed = strlen($component->html());
    $open = strlen($component->set('unitModal', true)->html());

    // The closed payload does NOT grow with the org tree — 199 more units
    // must not add a single byte to the closed page. (A leaked tree would add
    // tens of kilobytes here.)
    expect($closed - $before)->toBeLessThan(1_000);

    // ...while the OPEN path really does carry the tree: with 200 units it is
    // worth tens of kilobytes of HTML plus the inline JSON.
    expect($open - $closed)->toBeGreaterThan(20_000);

    // Structurally: the closed page holds no tree and no inline unit JSON.
    $component->set('unitModal', false)
        ->assertDontSeeHtml('flat:');
});

/**
 * #1040: `allUnits` / `allUnitsTree` became `#[Computed]` properties — methods
 * are not serialized into the snapshot, so the org-wide unit lists no longer
 * travel with every roundtrip. This locks that: the two list keys must not be
 * present in the hydratable data at all (the payload lock is the very point
 * of this card; the scope assertions above prove nothing was lost).
 */
test('#1040: users.index snapshot carries no unit lists', function () {
    $f = users849Fixtures();

    $component = Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->assertOk();

    $data = $component->snapshot['data'];

    expect($data)->not->toHaveKey('allUnits');
    expect($data)->not->toHaveKey('allUnitsTree');
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

// ──────────────────────────────────────────────
// Issue #1030 — admin password reset revokes the target's tokens
// ──────────────────────────────────────────────

test('#1030: admin password reset revokes the target user\'s tokens', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $target = users1030Target($this->unit, '9070000001');
    $tokenId = $target->createToken('flutter-app')->accessToken->id;

    expect($target->tokens()->count())->toBe(1);

    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$role->id])
        ->set('password', 'new-secret-123')
        ->call('updateUser')
        ->assertHasNoErrors();

    expect(Hash::check('new-secret-123', $target->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    expect($target->tokens()->count())->toBe(0);
});

test('#1030: editing a user without a password keeps their tokens alive', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $target = users1030Target($this->unit, '9070000002');
    $tokenId = $target->createToken('flutter-app')->accessToken->id;

    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$role->id])
        ->call('updateUser')
        ->assertHasNoErrors();

    // A plain profile/role edit must NOT log the user's devices out — the
    // revocation is guarded on an actually-changed password, not the method.
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
    expect($target->tokens()->count())->toBe(1);
});

test('#1030: admin password reset revokes every token, including map-dashboard', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $target = users1030Target($this->unit, '9070000003');
    $flutterId = $target->createToken('flutter-app')->accessToken->id;
    $mapId = $target->createToken('map-dashboard')->accessToken->id;

    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$role->id])
        ->set('password', 'new-secret-123')
        ->call('updateUser')
        ->assertHasNoErrors();

    // Option الف-۱ from the expert comment: revoke ALL tokens, exactly like the
    // self-service path — so `map-dashboard` dies too. This test pins that as a
    // deliberate decision, not an accident.
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $flutterId]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mapId]);
    expect($target->tokens()->count())->toBe(0);
});

test('#1030: both password-change paths revoke all of an account\'s tokens', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    // Path A — admin reset through users.index/updateUser().
    $targetA = users1030Target($this->unit, '9070000004');
    $targetA->createToken('flutter-app');

    Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $targetA->id)
        ->set('role_ids', [$role->id])
        ->set('password', 'new-secret-123')
        ->call('updateUser')
        ->assertHasNoErrors();

    expect($targetA->tokens()->count())->toBe(0);

    // Path B — the user's own change-password form. Same contract: zero tokens.
    $targetB = users1030Target($this->unit, '9070000005');
    $targetB->createToken('flutter-app');

    Livewire::actingAs($targetB)->test('auth.changepassword')
        ->set('currentPassword', 'password')
        ->set('newPassword', 'new-secret-123')
        ->set('newPasswordConfirmation', 'new-secret-123')
        ->call('changePassword');

    expect($targetB->tokens()->count())->toBe(0);
});

// Issue #1028: the account-to-person binding itself is a scope boundary.
// AccessService reads scope from the bound person's unit when the user has
// no context and no `user_units` rows, so an out-of-scope `n_code` on
// createUser() would mint an account whose inherited scope is a foreign
// unit — horizontal privilege escalation through a "validating" field.
test('#1028: createUser refuses a foreign unit person with no account yet', function () {
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    // A person in unit B WITHOUT any account: this is exactly the row an
    // attacker has to point a fresh account at.
    Person::create([
        'n_code' => '9077777777',
        'f_name' => 'بیرونی',
        'l_name' => 'واحد ب',
        'u_id' => $f['unitB']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    // unit_ids = [] passes assertUnitIdsInScope() — the person rule is the
    // guard that has to fail here.
    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9077777777')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [])
        ->call('createUser')
        ->assertHasErrors(['n_code']);

    // No account was minted from the foreign person.
    expect(User::where('n_code', '9077777777')->exists())->toBeFalse();
});

test('#1028: updateUser cannot re-bind an in-scope user to an out-of-scope person', function () {
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    // The colleague's person stays in the attacker's own unit.
    Person::create([
        'n_code' => '9066666666',
        'f_name' => 'همکار',
        'l_name' => 'واحد الف',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $colleague = User::create(['n_code' => '9066666666', 'password' => bcrypt('secret-1234')]);
    $colleague->units()->attach($f['unitA']->id, ['role' => 'staff', 'is_primary' => true]);

    // A foreign person WITHOUT an account: re-binding the colleague onto it
    // must be refused — unique passes, so only the scope rule can refuse.
    Person::create([
        'n_code' => '9077777777',
        'f_name' => 'بیرونی',
        'l_name' => 'واحد ب',
        'u_id' => $f['unitB']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->set('editing_user_id', $colleague->id)
        ->set('n_code', '9077777777')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [])
        ->call('updateUser')
        ->assertHasErrors(['n_code']);

    // The re-bind did not happen: n_code and its person's unit are untouched.
    expect($colleague->fresh()->n_code)->toBe('9066666666');
    expect($colleague->fresh()->person->u_id)->toBe($f['unitA']->id);
});

test('#1028: an empty scope refuses even an in-scope person n_code', function () {
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    // A person sitting in the actor's OWN unit — only the scope notion can
    // discard it once the mock returns [].
    Person::create([
        'n_code' => '9044444444',
        'f_name' => 'همکار',
        'l_name' => 'واحد الف',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    // `[]` means "in scope of nothing", never "unrestricted" (AGENTS.md) —
    // even an in-scope-named person must be refused when the scope is [].
    $this->mock(AccessService::class, function ($mock) {
        $mock->shouldReceive('accessibleUnitIds')->andReturn([]);
    });

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9044444444')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [])
        ->call('createUser')
        ->assertHasErrors(['n_code']);

    expect(User::where('n_code', '9044444444')->exists())->toBeFalse();
});

test('#1028: createUser refuses a person with no unit (u_id null)', function () {
    $f = users849Fixtures(['manage_users', 'manage_roles']);
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    // `persons.u_id` is nullable on beta (migration 2026_09_03_002219, FK
    // `onDelete('set null')`), and a NULL `u_id` matches no `IN` list in SQL
    // — the rule is expected to fail closed on it. This test pins that.
    Person::create([
        'n_code' => '9055555555',
        'f_name' => 'بدون',
        'l_name' => 'واحد',
        'u_id' => null,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    Livewire::actingAs($f['attacker'])
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9055555555')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [])
        ->call('createUser')
        ->assertHasErrors(['n_code']);

    expect(User::where('n_code', '9055555555')->exists())->toBeFalse();
});

test('#1028: the n_code exists rule itself constrains u_id to the accessible units', function () {
    $f = users849Fixtures();

    Person::create([
        'n_code' => '9044444444',
        'f_name' => 'همکار',
        'l_name' => 'واحد الف',
        'u_id' => $f['unitA']->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    $component = Livewire::actingAs($f['attacker'])->test('users.index');
    $instance = $component->instance();

    // Pull the rule object the component itself builds — the guard against
    // someone swapping it back down to a bare `exists:persons,n_code`.
    $method = new ReflectionMethod(get_class($instance), 'personNCodeExistsRule');
    $rule = $method->invoke($instance);

    $v = validator(['n_code' => '9044444444'], ['n_code' => [$rule]]);
    expect($v->passes())->toBeTrue();

    $v = validator(['n_code' => $f['victim']->n_code], ['n_code' => [$rule]]);
    expect($v->passes())->toBeFalse();
    expect($v->errors()->has('n_code'))->toBeTrue();
});

/*
 * ---------------------------------------------------------------------------
 * Issue #1277 — user create/edit must be atomic
 * ---------------------------------------------------------------------------
 * Each test arms a query listener that throws on the FIRST insert into the
 * named table, so a partially written user can never be observed. The listener
 * disarms itself after firing: Pest test files share one DB connection, and a
 * listener left armed would fail every later insert in the process.
 */
function users1277BoomOnInsert(string $table): void
{
    $armed = true;

    DB::listen(function ($query) use ($table, &$armed) {
        $sql = strtolower(ltrim($query->sql));

        if ($armed && str_starts_with($sql, 'insert') && str_contains($sql, $table)) {
            $armed = false;

            throw new RuntimeException('boom-'.$table);
        }
    });
}

test('#1277: a failed role sync leaves no user row behind on create', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    Person::create([
        'n_code' => '1277000001',
        'f_name' => 'نیمه',
        'l_name' => 'ساخته',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    users1277BoomOnInsert('model_has_roles');

    expect(fn () => Livewire::actingAs($this->admin)->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '1277000001')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [$this->unit->id])
        ->call('createUser'))->toThrow(RuntimeException::class);

    expect(User::where('n_code', '1277000001')->exists())->toBeFalse();
    expect(DB::table('model_has_roles')->count())->toBe(0);
});

test('#1277: a failed unit sync leaves no user row behind on create', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    Person::create([
        'n_code' => '1277000002',
        'f_name' => 'نیمه',
        'l_name' => 'ساخته',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    users1277BoomOnInsert('user_units');

    expect(fn () => Livewire::actingAs($this->admin)->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '1277000002')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->set('unit_ids', [$this->unit->id])
        ->call('createUser'))->toThrow(RuntimeException::class);

    expect(User::where('n_code', '1277000002')->exists())->toBeFalse();
    expect(DB::table('model_has_roles')->count())->toBe(0);
});

test('#1277: a failed unit sync on edit leaves password, role, unit and tokens untouched', function () {
    $oldRole = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $newRole = Role::create(['name' => 'supervisor', 'label' => 'سرپرست']);
    $child = Unit::create(['name' => 'واحد فرزند', 'parent_id' => $this->unit->id]);

    $target = users1030Target($this->unit, '1277000003');
    $target->assignRole($oldRole);
    $tokenId = $target->createToken('flutter-app')->accessToken->id;

    // A DIFFERENT in-scope unit must be submitted: syncing the same set writes
    // nothing, so the listener would never fire.
    users1277BoomOnInsert('user_units');

    expect(fn () => Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$newRole->id])
        ->set('password', 'new-secret-123')
        ->set('unit_ids', [$child->id])
        ->call('updateUser'))->toThrow(RuntimeException::class);

    expect(Hash::check('password', $target->fresh()->password))->toBeTrue();
    expect($target->fresh()->hasRole($oldRole->name))->toBeTrue();
    expect($target->fresh()->hasRole($newRole->name))->toBeFalse();
    expect($target->fresh()->units()->pluck('units.id')->all())->toBe([$this->unit->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
});

test('#1277: a failed role sync on a password reset leaves the old password and tokens alive', function () {
    $oldRole = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
    $newRole = Role::create(['name' => 'supervisor', 'label' => 'سرپرست']);

    $target = users1030Target($this->unit, '1277000004');
    $target->assignRole($oldRole);
    $tokenId = $target->createToken('flutter-app')->accessToken->id;

    users1277BoomOnInsert('model_has_roles');

    expect(fn () => Livewire::actingAs($this->admin)->test('users.index')
        ->call('edit', $target->id)
        ->set('role_ids', [$newRole->id])
        ->set('password', 'new-secret-123')
        ->call('updateUser'))->toThrow(RuntimeException::class);

    expect(Hash::check('password', $target->fresh()->password))->toBeTrue();
    expect($target->fresh()->hasRole($oldRole->name))->toBeTrue();
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
});
