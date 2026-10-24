<?php

use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #975 — the #892 remainder. Nine Livewire components are gated by a
 * route middleware (`role_or_permission:manage_hardware`, `organization`,
 * `kargozini`) that runs exactly once, on the page load.
 *
 * Livewire serves every follow-up action from `POST /livewire/update`, which
 * is NOT the route the tab was opened on, and `PersistentMiddleware` re-runs a
 * fixed vendor list that does not contain Spatie's `RoleOrPermissionMiddleware`
 * (this app never calls `Livewire::addPersistentMiddleware()`). So a holder
 * whose permission was revoked mid-session kept full CRUD on that open tab
 * until they navigated away. Every mutator below therefore re-checks its own
 * permission as its first statement.
 *
 * The contract each test proves:
 *
 *   1. MOUNT the component while the permission is still held.
 *   2. Run the fixture against that ALREADY-MOUNTED instance, so the row
 *      belongs to the same actor and unit the tab was opened with. (Seeding
 *      the row AFTER the revoke lets a test pass for the wrong reason — the
 *      row sits outside the actor's scope and the scope check refuses it
 *      before authorization is ever consulted.)
 *   3. REVOKE the permission and flush Spatie's cache.
 *   4. Call the mutator on that same instance and assert BOTH `assertForbidden()`
 *      AND the database row. A mutator that failed *validation* would also
 *      leave the row untouched; together the two assertions prove the refusal
 *      came from authorization.
 *
 * The positive tests at the bottom are not optional decoration. Spatie's
 * `hasAnyPermission()` answers `false` for an unknown permission name instead
 * of throwing, so `$this->authorize('manage_hardwares')` (one `s`) fails
 * CLOSED: every negative test in this file would still pass while the page is
 * locked for everyone who legitimately holds the permission.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    // `units.index` mounts through `determineUserLevel()` and
    // `loadDropdowns()`, which both read these tables. Without them the
    // component cannot mount at all and every units test below would fail for
    // a reason that has nothing to do with authorization. Mirrors
    // `UnitsIndexLivewireTest::setUp()`.
    DB::table('unit_types')->insert([
        ['id' => 1, 'name' => 'وزارت بهداشت', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'دانشگاه علوم پزشکی', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'معاونت بهداشت', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'شبکه بهداشت', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 5, 'name' => 'مرکز خدمات جامع سلامت شهری', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('unit_type_relationships')->insert([
        ['child_unit_type_id' => 2, 'allowed_parent_unit_type_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['child_unit_type_id' => 3, 'allowed_parent_unit_type_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['child_unit_type_id' => 4, 'allowed_parent_unit_type_id' => 3, 'created_at' => now(), 'updated_at' => now()],
        ['child_unit_type_id' => 5, 'allowed_parent_unit_type_id' => 4, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('regions')->insert([
        ['id' => 1, 'name' => 'استان تست', 'type' => 'province', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'شهرستان الف', 'type' => 'county', 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Postgres sequences are non-transactional, so pin them explicitly rather
    // than relying on test ordering.
    foreach (['unit_types', 'regions'] as $table) {
        DB::statement("SELECT setval('{$table}_id_seq', (SELECT MAX(id) FROM {$table}), true)");
    }

    // `units/index.blade.php:92` hardcodes `unit id 1` as the ministry HQ and
    // branches on it. Pin the sequence so created units start at id 2 and the
    // level logic falls through to the region-based branches.
    DB::statement("SELECT setval('units_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM units), 1), 1))");
});

/**
 * Mount `hardware.index` while the actor still holds `manage_hardware`, run
 * `$seed` against the already-mounted component, then revoke — reproducing an
 * open tab whose permission was revoked mid-session, mid-edit.
 *
 * @param  (callable(User, Unit): void)|null  $seed
 */
function revokedHardwareTab(?Closure $seed = null): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit(['manage_hardware']);
    test()->actingAs($user);

    $component = Livewire::test('hardware.index')->assertStatus(200);

    if ($seed) {
        $seed($user, $unit);
    }

    $user->revokePermissionTo('manage_hardware');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $unit, $component];
}

/**
 * Same shape for `units.index` (gated by `organization`).
 *
 * @param  (callable(User, Unit): void)|null  $seed
 */
function revokedUnitsTab(?Closure $seed = null): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit(['organization']);
    test()->actingAs($user);

    $component = Livewire::test('units.index')->assertStatus(200);

    if ($seed) {
        $seed($user, $unit);
    }

    $user->revokePermissionTo('organization');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $unit, $component];
}

/**
 * Same shape for the four `kargozini` lookup components.
 *
 * @param  (callable(User, Unit): void)|null  $seed
 */
function revokedKargoziniTab(string $component, ?Closure $seed = null): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit(['kargozini']);
    test()->actingAs($user);

    $instance = Livewire::test($component)->assertStatus(200);

    if ($seed) {
        $seed($user, $unit, $instance);
    }

    $user->revokePermissionTo('kargozini');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $unit, $instance];
}

/**
 * Same shape for `todo.todo` (gated by `calendar`, Issue #978).
 *
 * @param  (callable(User, Unit, mixed): void)|null  $seed
 */
function revokedTodoTab(?Closure $seed = null): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit(['calendar']);
    test()->actingAs($user);

    $component = Livewire::test('todo.todo')->assertStatus(200);

    if ($seed) {
        $seed($user, $unit, $component);
    }

    $user->revokePermissionTo('calendar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $unit, $component];
}

/**
 * A hardware row owned by the given person, inside the actor's own unit so
 * every scope check in `hardware.index` passes once authorization does.
 */
function hardwareFor(User $user, array $overrides = []): Hardware
{
    $person = Person::where('n_code', $user->n_code)->firstOrFail();

    return Hardware::create(array_merge([
        'n_code' => $person->n_code,
        'pc_name' => 'PC-'.fake()->unique()->bothify('####'),
        'type' => 'PC',
        'os' => 'Windows 10',
        'cpu' => 'Intel i5',
        'ram' => '8GB',
        'hdd' => '256GB SSD',
        'mark' => false,
    ], $overrides));
}

// ======================================================================
// hardware.index — six mutators, `manage_hardware`
// ======================================================================

test('createHardware is refused after manage_hardware is revoked', function () {
    [, $unit, $component] = revokedHardwareTab();

    $person = Person::factory()->create(['u_id' => $unit->id]);

    $component
        ->set('n_code', $person->n_code)
        ->set('pc_name', 'EscalatedHw975')
        ->call('createHardware')
        ->assertForbidden();

    $this->assertDatabaseMissing('hardwares', ['pc_name' => 'EscalatedHw975']);
});

test('updateHardware is refused after manage_hardware is revoked', function () {
    [, , $component] = revokedHardwareTab(function ($user) {
        $this->hardware = hardwareFor($user, ['pc_name' => 'PC-Original-975']);
    });

    // Populate the edit form while the permission is still held, so the only
    // thing the update call can be refused by is authorization.
    $component->call('editHardware', $this->hardware->id)->assertStatus(200);

    $component
        ->set('pc_name', 'PC-Hijacked-975')
        ->call('updateHardware')
        ->assertForbidden();

    $this->assertDatabaseHas('hardwares', ['id' => $this->hardware->id, 'pc_name' => 'PC-Original-975']);
});

test('delete hardware is refused with 403 after manage_hardware is revoked', function () {
    [, , $component] = revokedHardwareTab(function ($user) {
        $this->hardware = hardwareFor($user, ['pc_name' => 'PC-DeleteMe-975']);
    });

    $component->call('delete', $this->hardware->id)->assertForbidden();

    $this->assertDatabaseHas('hardwares', ['id' => $this->hardware->id]);
});

test('bulkMark is refused after manage_hardware is revoked', function () {
    [, , $component] = revokedHardwareTab(function ($user) {
        $this->hardware = hardwareFor($user, ['pc_name' => 'PC-Mark-975', 'mark' => false]);
    });

    $component
        ->set('selected', [$this->hardware->id])
        ->call('bulkMark', true)
        ->assertForbidden();

    $this->assertDatabaseHas('hardwares', ['id' => $this->hardware->id, 'mark' => false]);
});

test('bulkDelete is refused after manage_hardware is revoked', function () {
    [, , $component] = revokedHardwareTab(function ($user) {
        $this->hardware = hardwareFor($user, ['pc_name' => 'PC-BulkDelete-975']);
    });

    $component
        ->set('selected', [$this->hardware->id])
        ->call('bulkDelete')
        ->assertForbidden();

    $this->assertDatabaseHas('hardwares', ['id' => $this->hardware->id]);
});

test('exportExcel is refused after manage_hardware is revoked', function () {
    [, , $component] = revokedHardwareTab(function ($user) {
        $this->hardware = hardwareFor($user, ['pc_name' => 'PC-Export-975']);
    });

    $component->call('exportExcel')->assertForbidden();

    // Nothing to assert on disk: the mutator only builds a route and dispatches
    // a browser download. `assertForbidden()` is the whole contract here.
    $this->assertDatabaseHas('hardwares', ['id' => $this->hardware->id]);
});

// ======================================================================
// units.index — four mutators, `organization`
// ======================================================================

test('saveUnit create is refused after organization is revoked', function () {
    [, , $component] = revokedUnitsTab();

    $component
        ->set('name', 'واحد-۹۷۵-ممنوع')
        ->set('unit_type_id', 1)
        ->call('saveUnit')
        ->assertForbidden();

    $this->assertDatabaseMissing('units', ['name' => 'واحد-۹۷۵-ممنوع']);
});

test('editUnit is refused after organization is revoked', function () {
    [, , $component] = revokedUnitsTab(function ($user, $unit) {
        $this->target = Unit::factory()->create(['name' => 'واحد-قابل-ویرایش', 'parent_id' => $unit->id]);
    });

    $component->call('editUnit', $this->target->id)->assertForbidden();

    $this->assertNull($component->get('editingId'));
});

test('deleteUnit is refused after organization is revoked', function () {
    [, , $component] = revokedUnitsTab(function ($user, $unit) {
        $this->target = Unit::factory()->create(['name' => 'واحد-حذف-۹۷۵', 'parent_id' => $unit->id]);
    });

    $component->call('deleteUnit', $this->target->id)->assertForbidden();

    $this->assertDatabaseHas('units', ['id' => $this->target->id]);
});

test('setInactive is refused after organization is revoked', function () {
    [, , $component] = revokedUnitsTab(function ($user, $unit) {
        $this->target = Unit::factory()->create([
            'name' => 'واحد-غیرفعال-۹۷۵',
            'parent_id' => $unit->id,
            'is_active' => true,
        ]);
    });

    $component->call('setInactive', $this->target->id, false)->assertForbidden();

    $this->assertDatabaseHas('units', ['id' => $this->target->id, 'is_active' => true]);
});

// ======================================================================
// kargozini.{estekhdam,tahsil,semat,radif} — four mutators each, `kargozini`
// ======================================================================

/**
 * The four lookup components are structurally identical, so each row carries
 * every method name the component exposes.
 *
 * Each row is wrapped in a one-element list on purpose: Pest spreads a list
 * dataset positionally and an associative one as NAMED arguments, and `class`
 * is a reserved word that cannot be a parameter name. One positional array
 * keeps every row below readable and every closure signature identical.
 */
dataset('kargoziniLookups', [
    'estekhdam' => [['component' => 'kargozini.estekhdam', 'table' => 'estekhdams', 'create' => 'createEstekhdam', 'edit' => 'editEstekhdam', 'update' => 'updateEstekhdam', 'model' => Estekhdam::class]],
    'tahsil' => [['component' => 'kargozini.tahsil', 'table' => 'tahsils', 'create' => 'createTahsil', 'edit' => 'editTahsil', 'update' => 'updateTahsil', 'model' => Tahsil::class]],
    'semat' => [['component' => 'kargozini.semat', 'table' => 'semats', 'create' => 'createSemat', 'edit' => 'editSemat', 'update' => 'updateSemat', 'model' => Semat::class]],
    'radif' => [['component' => 'kargozini.radif', 'table' => 'radifs', 'create' => 'createRadif', 'edit' => 'editRadif', 'update' => 'updateRadif', 'model' => Radif::class]],
]);

test('delete on the lookup page is refused after kargozini is revoked', function (array $lookup) {
    [, , $instance] = revokedKargoziniTab($lookup['component'], function () use ($lookup) {
        $this->row = $lookup['model']::create(['name' => 'نام-'.$lookup['table'].'-۹۷۵']);
    });

    $instance->call('delete', $this->row->id)->assertForbidden();

    $this->assertDatabaseHas($lookup['table'], ['id' => $this->row->id]);
})->with('kargoziniLookups');

test('create on the lookup page is refused after kargozini is revoked', function (array $lookup) {
    [, , $instance] = revokedKargoziniTab($lookup['component']);

    $instance
        ->set('name', 'نام-جدید-'.$lookup['table'].'-۹۷۵')
        ->call($lookup['create'])
        ->assertForbidden();

    $this->assertDatabaseMissing($lookup['table'], ['name' => 'نام-جدید-'.$lookup['table'].'-۹۷۵']);
})->with('kargoziniLookups');

test('update on the lookup page is refused after kargozini is revoked', function (array $lookup) {
    // The row is created AND loaded into the form while the permission is still
    // held — `edit*` is itself gated, so loading it after the revoke would 403
    // on the form fill and the update would never be exercised.
    [, , $instance] = revokedKargoziniTab($lookup['component'], function ($user, $unit, $tab) use ($lookup) {
        $this->row = $lookup['model']::create(['name' => 'نام-'.$lookup['table'].'-اصلی']);
        $tab->call($lookup['edit'], $this->row->id)->assertStatus(200);
    });

    $instance
        ->set('name', 'نام-'.$lookup['table'].'-دزدیده')
        ->call($lookup['update'])
        ->assertForbidden();

    $this->assertDatabaseHas($lookup['table'], ['id' => $this->row->id, 'name' => 'نام-'.$lookup['table'].'-اصلی']);
})->with('kargoziniLookups');

// `edit*` only fills a form, but it copies the row's attributes into public
// component state, so it is gated like every other mutator — and therefore has
// to be tested like every other mutator. (Same reason `roles/index`'s
// `editRole` is covered by a pinned test since #892.)
test('edit on the lookup page is refused after kargozini is revoked', function (array $lookup) {
    [, , $instance] = revokedKargoziniTab($lookup['component'], function () use ($lookup) {
        $this->row = $lookup['model']::create(['name' => 'نام-'.$lookup['table'].'-خصوصی']);
    });

    $instance->call($lookup['edit'], $this->row->id)->assertForbidden();

    $this->assertNull($instance->get('editingId'));
    $this->assertSame('', (string) $instance->get('name'));
})->with('kargoziniLookups');

// ======================================================================
// todo.todo — mutators and calendar actions, `calendar` (Issue #978)
// ======================================================================

test('save create todo is refused after calendar is revoked', function () {
    [, $unit, $component] = revokedTodoTab();

    $component
        ->set('title', 'تسک جدید ۹۷۸')
        ->set('unit_id', $unit->id)
        ->set('start_date_picker', '1405/07/01')
        ->call('save')
        ->assertForbidden();

    $this->assertDatabaseMissing('todos', ['title' => 'تسک جدید ۹۷۸']);
});

test('save update todo is refused after calendar is revoked', function () {
    [, $unit, $component] = revokedTodoTab(function ($user, $unit, $comp) {
        $this->todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'title' => 'عنوان اصلی ۹۷۸',
        ]);
        $comp->call('editEvent', $this->todo->id);
    });

    $component
        ->set('title', 'عنوان تغییریافته ۹۷۸')
        ->set('start_date_picker', '1405/07/01')
        ->call('save')
        ->assertForbidden();

    $this->assertDatabaseHas('todos', [
        'id' => $this->todo->id,
        'title' => 'عنوان اصلی ۹۷۸',
    ]);
});

test('editEvent on todo is refused after calendar is revoked', function () {
    [, $unit, $component] = revokedTodoTab(function ($user, $unit) {
        $this->todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'title' => 'عنوان اصلی ۹۷۸',
        ]);
    });

    $component->call('editEvent', $this->todo->id)->assertForbidden();

    $this->assertNull($component->get('editingId'));
});

test('delete todo is refused with 403 after calendar is revoked', function () {
    [, $unit, $component] = revokedTodoTab(function ($user, $unit, $comp) {
        $this->todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'title' => 'قابل حذف ۹۷۸',
        ]);
        $comp->call('editEvent', $this->todo->id);
    });

    $component->call('delete')->assertForbidden();

    $this->assertDatabaseHas('todos', ['id' => $this->todo->id]);
});

test('toggleComplete on todo is refused after calendar is revoked', function () {
    [, $unit, $component] = revokedTodoTab(function ($user, $unit) {
        $this->todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'is_completed' => false,
        ]);
    });

    $component->call('toggleComplete', $this->todo->id)->assertForbidden();

    $this->assertFalse((bool) $this->todo->fresh()->is_completed);
});

test('updateTodoDates is refused after calendar is revoked', function () {
    [, $unit, $component] = revokedTodoTab(function ($user, $unit) {
        $this->todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'start_at' => '2026-10-01 10:00:00',
            'end_at' => '2026-10-01 12:00:00',
        ]);
    });

    $component->call('updateTodoDates', (string) $this->todo->id, '2026-10-10 10:00:00', '2026-10-10 12:00:00')
        ->assertForbidden();

    $this->assertNotEquals('2026-10-10 10:00:00', $this->todo->fresh()->start_at?->format('Y-m-d H:i:s'));
});

test('fetchEvents is refused after calendar is revoked', function () {
    [, , $component] = revokedTodoTab();

    $component->call('fetchEvents', '2026-10-01', '2026-10-31')->assertForbidden();
});

test('openModal is refused after calendar is revoked', function () {
    [, , $component] = revokedTodoTab();

    $component->call('openModal')->assertForbidden();
});

test('openCreateModal is refused after calendar is revoked', function () {
    [, , $component] = revokedTodoTab();

    $component->call('openCreateModal', '2026-10-01', '2026-10-05')->assertForbidden();
});

// ======================================================================
// Controls — the route gate still works, and a real holder is not locked out
// ======================================================================

test('a fresh load still 403s while revoked', function () {
    // Control for the two page groups above: the route gate is unchanged, this
    // fix is about mutators on an ALREADY-mounted component.
    ['user' => $user] = $this->createUserWithUnit(['manage_hardware', 'organization']);
    $this->actingAs($user);
    $this->get('/hardware')->assertStatus(200);
    $this->get('/units')->assertStatus(200);

    $user->revokePermissionTo('manage_hardware');
    $user->revokePermissionTo('organization');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get('/hardware')->assertStatus(403);
    $this->get('/units')->assertStatus(403);
});

test('a fresh load still 403s on kargozini while revoked', function () {
    ['user' => $user] = $this->createUserWithUnit(['kargozini']);
    $this->actingAs($user);
    $this->get('/kargozini/estekhdams')->assertStatus(200);

    $user->revokePermissionTo('kargozini');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get('/kargozini/estekhdams')->assertStatus(403);
});

test('a fresh load still 403s on todo while revoked', function () {
    ['user' => $user] = $this->createUserWithUnit(['calendar']);
    $this->actingAs($user);
    $this->get('/todo')->assertStatus(200);

    $user->revokePermissionTo('calendar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get('/todo')->assertStatus(403);
});

test('a holder who keeps manage_hardware can still create hardware', function () {
    ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
    $this->actingAs($user);
    $person = Person::factory()->create(['u_id' => $unit->id]);

    Livewire::test('hardware.index')
        ->set('n_code', $person->n_code)
        ->set('pc_name', 'PC-Allowed-975')
        ->call('createHardware')
        ->assertStatus(200);

    $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-Allowed-975']);
});

test('a holder who keeps organization can still create a unit', function () {
    ['user' => $user] = $this->createUserWithUnit(['organization']);
    $this->actingAs($user);

    Livewire::test('units.index')
        ->set('name', 'واحد-مجاز-۹۷۵')
        ->set('unit_type_id', 1)
        ->call('saveUnit')
        ->assertStatus(200);

    $this->assertDatabaseHas('units', ['name' => 'واحد-مجاز-۹۷۵']);
});

test('a holder who keeps kargozini can still create a lookup row', function (array $lookup) {
    ['user' => $user] = $this->createUserWithUnit(['kargozini']);
    $this->actingAs($user);

    Livewire::test($lookup['component'])
        ->set('name', 'نام-مجاز-'.$lookup['table'].'-۹۷۵')
        ->call($lookup['create'])
        ->assertStatus(200);

    $this->assertDatabaseHas($lookup['table'], ['name' => 'نام-مجاز-'.$lookup['table'].'-۹۷۵']);
})->with('kargoziniLookups');

test('a holder who keeps calendar can still create a todo', function () {
    ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
    $this->actingAs($user);

    Livewire::test('todo.todo')
        ->set('title', 'وظیفه-مجاز-۹۷۸')
        ->set('unit_id', $unit->id)
        ->set('start_date_picker', '1405/07/01')
        ->call('save')
        ->assertStatus(200);

    $this->assertDatabaseHas('todos', ['title' => 'وظیفه-مجاز-۹۷۸']);
});
