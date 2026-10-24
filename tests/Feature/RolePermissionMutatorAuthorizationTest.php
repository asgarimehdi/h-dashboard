<?php

use App\Jobs\ArchiveActivityLogsJob;
use App\Jobs\CleanNotificationsJob;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Ticket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #892 — privilege-escalation primitive on the roles / permissions /
 * tools pages.
 *
 * These three pages are gated by `role_or_permission:manage_roles`
 * (`manage_users` for tools) on their routes, and `mount()` re-checked the
 * same permission. But Livewire does not run route middleware on
 * `/livewire/update` unless the middleware class is registered as persistent
 * (`Livewire::addPersistentMiddleware()`), so `mount()` was the ONLY check:
 * a session that lost `manage_roles` while the tab stayed open could still
 * call every mutator on the already-mounted component.
 *
 * Each test mounts the component while the permission is still held, revokes
 * it, flushes the Spatie cache and then calls a mutator on the SAME instance.
 *
 * The row assertion is load-bearing: a mutator that threw a *validation*
 * error instead of an authorization error would also "not write a row", so
 * `assertForbidden()` plus the database assertion together prove the write
 * was refused by authorization and not by validation.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
});

/**
 * Mount `roles/index` while the actor still holds `manage_roles`, run `$seed`
 * against the already-mounted component, then revoke — reproducing an open tab
 * whose permission was revoked mid-session, mid-edit.
 */
function revokedRolesTab(?Closure $seed = null): array
{
    ['user' => $user] = test()->createUserWithUnit(['manage_roles']);
    test()->actingAs($user);

    $component = Livewire::test('roles/index')->assertStatus(200);

    if ($seed) {
        $seed($component);
    }

    $user->revokePermissionTo('manage_roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $component];
}

/**
 * Same shape for `permissions/index`.
 */
function revokedPermissionsTab(?Closure $seed = null): array
{
    ['user' => $user] = test()->createUserWithUnit(['manage_roles']);
    test()->actingAs($user);

    $component = Livewire::test('permissions/index')->assertStatus(200);

    if ($seed) {
        $seed($component);
    }

    $user->revokePermissionTo('manage_roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $component];
}

/**
 * Same shape for `tools.tools` (gated by `manage_users`).
 *
 * `$seed` runs after the component is mounted and before the revoke, so the
 * fixture data belongs to the SAME actor and unit the tab was opened with —
 * otherwise `archiveTickets()` would pass for the wrong reason (the ticket
 * sitting in another unit that the actor cannot reach anyway).
 */
function revokedToolsTab(?Closure $seed = null): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit(['manage_users']);
    test()->actingAs($user);

    $component = Livewire::test('tools.tools')->assertStatus(200);

    if ($seed) {
        $seed($user, $unit);
    }

    $user->revokePermissionTo('manage_users');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $component];
}

// ======================================================================
// roles/index — all four mutators
// ======================================================================

test('createRole is refused after manage_roles is revoked', function () {
    [, $component] = revokedRolesTab();

    $component
        ->set('name', 'EscalatedRole892')
        ->set('label', 'نقvt ارتقا')
        ->set('permissions', ['manage_roles'])
        ->call('createRole')
        ->assertForbidden();

    $this->assertDatabaseMissing('roles', ['name' => 'EscalatedRole892']);
});

test('updateRole is refused after manage_roles is revoked', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    [, $component] = revokedRolesTab(fn ($c) => $c->call('editRole', $role->id));

    $component
        ->set('name', 'operatorHijacked')
        ->set('label', 'اپراتور تغییریافته')
        ->set('permissions', ['manage_roles'])
        ->call('updateRole')
        ->assertForbidden();

    $this->assertDatabaseHas('roles', ['name' => 'operator', 'label' => 'اپراتور']);
});

test('editRole is refused after manage_roles is revoked', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    [, $component] = revokedRolesTab();
    $component->call('editRole', $role->id)->assertForbidden();

    $this->assertNull($component->get('editingId'));
});

test('delete role is refused with 403 after manage_roles is revoked', function () {
    $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    [, $component] = revokedRolesTab();
    $component->call('delete', $role->id)->assertForbidden();

    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

test('a fresh load still 403s while revoked', function () {
    // Control for the two-page tests above: the route gate is unchanged, this
    // fix is about the mutators on an ALREADY-mounted component.
    ['user' => $user] = $this->createUserWithUnit(['manage_roles']);
    $this->actingAs($user);
    $this->get('/roles')->assertStatus(200);

    $user->revokePermissionTo('manage_roles');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->get('/roles')->assertStatus(403);
    Livewire::test('roles/index')->assertStatus(403);
});

// ======================================================================
// permissions/index — all four mutators
// ======================================================================

test('createPermission is refused after manage_roles is revoked', function () {
    [, $component] = revokedPermissionsTab();

    $component
        ->set('name', 'escalated-permission-892')
        ->set('label', 'مجوز ارتقا')
        ->call('createPermission')
        ->assertForbidden();

    $this->assertDatabaseMissing('permissions', ['name' => 'escalated-permission-892']);
});

test('updatePermission is refused after manage_roles is revoked', function () {
    $permission = Permission::findByName('manage_hardware');

    [, $component] = revokedPermissionsTab(fn ($c) => $c->call('editPermission', $permission->id));

    $component
        ->set('name', 'hijacked-hardware-892')
        ->set('label', 'مجوز تغییریافته')
        ->call('updatePermission')
        ->assertForbidden();

    $this->assertDatabaseHas('permissions', [
        'id' => $permission->id,
        'name' => 'manage_hardware',
        'label' => $permission->label,
    ]);
});

test('editPermission is refused after manage_roles is revoked', function () {
    $permission = Permission::findByName('manage_hardware');

    [, $component] = revokedPermissionsTab();
    $component->call('editPermission', $permission->id)->assertForbidden();

    $this->assertNull($component->get('editingId'));
});

test('delete permission is refused with 403 after manage_roles is revoked', function () {
    $permission = Permission::findByName('manage_hardware');

    [, $component] = revokedPermissionsTab();
    $component->call('delete', $permission->id)->assertForbidden();

    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});

// ======================================================================
// tools.tools — all three mutators (org-wide deletions)
// ======================================================================

test('archiveTickets is refused after manage_users is revoked', function () {
    [, $component] = revokedToolsTab(function ($user, $unit) {
        Ticket::create([
            'ticket_code' => 'TKT-892-ARCHIVE',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت قدیمی ۸۹۲',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'completed',
            'completed_at' => now()->subDays(60),
        ]);
    });

    $component->set('archiveDays', 30)->call('archiveTickets')->assertForbidden();

    $this->assertDatabaseHas('tickets', ['ticket_code' => 'TKT-892-ARCHIVE', 'status' => 'completed']);
});

test('cleanActivities is refused after manage_users is revoked', function () {
    Queue::fake();

    [, $component] = revokedToolsTab(function ($user) {
        ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'test',
            'subject_type' => 'ticket',
            'subject_id' => 1,
            'description' => 'گزارش قدیمی ۸۹۲',
        ]);
        DB::table('activity_logs')->where('description', 'گزارش قدیمی ۸۹۲')
            ->update(['created_at' => now()->subDays(200)]);
    });

    $component->set('activityDays', 90)->call('cleanActivities')->assertForbidden();

    $this->assertDatabaseHas('activity_logs', ['description' => 'گزارش قدیمی ۸۹۲']);
    Queue::assertNotPushed(ArchiveActivityLogsJob::class);
});

test('cleanNotifications is refused after manage_users is revoked', function () {
    Queue::fake();

    [, $component] = revokedToolsTab(function ($user) {
        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'اعلان قدیمی ۸۹۲',
        ]);
        DB::table('notifications')->where('title', 'اعلان قدیمی ۸۹۲')
            ->update(['created_at' => now()->subDays(30)]);
    });

    $component->set('notificationDays', 7)->call('cleanNotifications')->assertForbidden();

    $this->assertDatabaseHas('notifications', ['title' => 'اعلان قدیمی ۸۹۲']);
    Queue::assertNotPushed(CleanNotificationsJob::class);
});

// ======================================================================
// The authorization check is the same one mount() applies
// ======================================================================

test('the mutator permission matches the page gate permission', function () {
    // Guards the #812-style mistake of gating a page with a permission other
    // than the one its route checks: roles/permissions are `manage_roles`,
    // tools is `manage_users`, hardware is `manage_hardware`, units is
    // `organization` and the four lookup tables are `kargozini`.
    //
    // Not every entry has a `mount()` — hardware/index and the lookup
    // components rely on the route gate alone — so the invariant under test is
    // "every mutator authorizes exactly the permission its route gate
    // requires", which is what a `$this->authorize(...)` call has to name.
    // Spatie answers `false` for an unknown permission name instead of
    // throwing, so a typo here (`manage_hardwares`) would fail CLOSED and turn
    // a green suite into a locked-out app. This scan is what catches it.
    $source = [
        'resources/views/livewire/roles/index.blade.php' => 'manage_roles',
        'resources/views/livewire/permissions/index.blade.php' => 'manage_roles',
        'resources/views/livewire/tools/tools.blade.php' => 'manage_users',
        'resources/views/livewire/hardware/index.blade.php' => 'manage_hardware',
        'resources/views/livewire/units/index.blade.php' => 'organization',
        'resources/views/livewire/kargozini/estekhdam.blade.php' => 'kargozini',
        'resources/views/livewire/kargozini/tahsil.blade.php' => 'kargozini',
        'resources/views/livewire/kargozini/semat.blade.php' => 'kargozini',
        'resources/views/livewire/kargozini/radif.blade.php' => 'kargozini',
        'resources/views/livewire/todo/todo.blade.php' => 'calendar',
    ];

    foreach ($source as $file => $permission) {
        $contents = file_get_contents(base_path($file));
        $this->assertIsString($contents);

        preg_match_all("/authorize\('([^']+)'\)/", $contents, $matches);

        $this->assertNotEmpty($matches[1], "{$file} has no authorize() call");
        foreach ($matches[1] as $found) {
            $this->assertSame($permission, $found, "{$file} authorizes '{$found}' instead of '{$permission}'");
        }
    }
});

test('AuthorizationException is not swallowed by the delete handlers', function () {
    // The generic `catch (\Exception $e)` -> toast is what made this bug
    // invisible: a 403 came back as a 200 with a friendly toast. Catching
    // AuthorizationException specifically and re-throwing keeps the 403
    // distinguishable from a foreign-key violation.
    foreach ([
        'resources/views/livewire/roles/index.blade.php',
        'resources/views/livewire/permissions/index.blade.php',
        'resources/views/livewire/hardware/index.blade.php',
        'resources/views/livewire/kargozini/estekhdam.blade.php',
        'resources/views/livewire/kargozini/tahsil.blade.php',
        'resources/views/livewire/kargozini/semat.blade.php',
        'resources/views/livewire/kargozini/radif.blade.php',
        'resources/views/livewire/todo/todo.blade.php',
    ] as $file) {
        $contents = file_get_contents(base_path($file));
        $this->assertIsString($contents);

        $this->assertStringContainsString(
            'AuthorizationException',
            $contents,
            "{$file} does not reference AuthorizationException"
        );

        $this->assertSame(
            1,
            preg_match_all('/catch \((\\\\)?\\\\?Illuminate\\\\Auth\\\\Access\\\\AuthorizationException \$e\)|catch \((\\\\)?AuthorizationException \$e\)/', $contents, $matches),
            "{$file} must catch AuthorizationException exactly once, in delete()"
        );

        // Match across the explanatory comment that sits between the catch and
        // the re-throw, but not across the following `catch (\Exception ...)`
        // block — so the `throw` must belong to the AuthorizationException
        // clause and not to some later handler.
        $this->assertSame(
            1,
            preg_match_all('/catch \((\\\\)?(\\\\?Illuminate\\\\Auth\\\\Access\\\\)?AuthorizationException \$e\) *\{(.*?)\}/s', $contents, $clauses),
            "{$file} must catch AuthorizationException in a block"
        );
        $this->assertStringContainsString(
            'throw $e;',
            $clauses[3][0],
            "{$file} does not re-throw AuthorizationException in delete()"
        );

        // ORDER is load-bearing and the checks above cannot see it.
        // `AuthorizationException extends Exception`, so if the generic catch
        // comes first PHP binds it first and the 403 IS swallowed into the
        // friendly toast — while `catch (AuthorizationException $e)` still
        // exists, still contains `throw $e;`, and every assertion above still
        // passes. That is the exact regression this test is named for, so the
        // ordering has to be asserted on its own.
        $authCatch = strpos($contents, 'AuthorizationException $e');
        $genericCatch = strpos($contents, 'catch (\\Exception $e');

        if ($genericCatch === false) {
            // `hardware/index.blade.php` imports neither; its generic catch is
            // `catch (Exception $e)`.
            $genericCatch = strpos($contents, 'catch (Exception $e');
        }

        $this->assertIsInt($authCatch, "{$file} does not catch AuthorizationException");
        $this->assertIsInt($genericCatch, "{$file} has no generic catch to order against");
        $this->assertLessThan(
            $genericCatch,
            $authCatch,
            "{$file} catches \\Exception before AuthorizationException, so a 403 is swallowed into the toast"
        );
    }
});
