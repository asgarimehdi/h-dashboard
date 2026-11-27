<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Issue #1607 — the delegation ceiling for `manage_roles` / `manage_users`.
 *
 * Managing roles is a delegation, not a blank cheque: a non-admin may hand out
 * only privileges it already holds. The `admin` ROLE is separately off-limits
 * because it carries `role:admin` route access (backup, `routes/web.php`) that
 * no permission represents and therefore cannot appear in a permission
 * ceiling — and its NAME is reserved too, so a non-admin cannot mint or rename
 * a role into `admin` and pick that route access up under the ceiling.
 *
 * The comparison is a DELTA (submitted minus current), so a delegated manager
 * can still save a user or role that already holds privileges above its own
 * ceiling, as long as the write does not ADD any. Removals are always allowed;
 * an `admin`-role holder bypasses the whole guard. The `assertAdmin…`
 * guards below are the exception (#1663): they have no actor bypass.
 */
final class RoleGuard
{
    public const ADMIN = 'admin';

    /**
     * Issue #1921 — the permission that gates `/permissions` and `/roles`.
     * It is protected by identity (like the `admin` role, #1663) because
     * deleting or renaming it locks everyone out with no in-app recovery.
     */
    public const MANAGEMENT_GATE = 'manage_roles';

    /**
     * The roles and direct permissions a `users.index` write would hand out.
     *
     * @param  array<int|string>  $roleIds
     * @param  array<int, string>  $permissionNames
     */
    public static function assertCanAssign(User $actor, ?User $target, array $roleIds, array $permissionNames): void
    {
        if ($actor->hasRole(self::ADMIN)) {
            return;
        }

        $roles = Role::query()
            ->with('permissions')
            ->whereIn('id', array_map('intval', $roleIds))
            ->get();

        if ($roles->contains(fn (Role $role): bool => $role->name === self::ADMIN)) {
            abort(403, 'تنها مدیران می‌توانند نقش مدیر را تخصیص دهند.');
        }

        $submitted = $roles
            ->flatMap(fn (Role $role): array => $role->permissions->pluck('name')->all())
            ->merge($permissionNames)
            ->all();

        $current = $target instanceof User
            ? $target->getAllPermissions()->pluck('name')->all()
            : [];

        self::assertNoAmplification($actor, $submitted, $current);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public static function assertCanCreateRole(User $actor, string $name, array $permissionNames): void
    {
        if ($actor->hasRole(self::ADMIN)) {
            return;
        }

        self::assertNameIsNotAdmin($name);

        self::assertNoAmplification($actor, $permissionNames, []);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public static function assertCanMutateRole(User $actor, Role $role, string $name, array $permissionNames): void
    {
        if ($actor->hasRole(self::ADMIN)) {
            return;
        }

        self::assertRoleIsNotAdmin($role);
        self::assertNameIsNotAdmin($name);

        self::assertNoAmplification($actor, $permissionNames, $role->permissions->pluck('name')->all());
    }

    /**
     * Deleting carries no submitted name, so only the `admin` identity applies.
     */
    public static function assertCanDeleteRole(User $actor, Role $role): void
    {
        if ($actor->hasRole(self::ADMIN)) {
            return;
        }

        self::assertRoleIsNotAdmin($role);
    }

    /**
     * Issue #1921 — permission deletion is part of the #1607 delegation
     * ceiling.
     *
     * A non-admin may delete only a permission assigned NOWHERE: a role pivot
     * OR a direct `model_has_permissions` grant. Any assignment means the
     * delete would strip that privilege from its holders — the
     * `role_has_permissions` FK is `onDelete('cascade')`.
     */
    public static function assertCanDeletePermission(User $actor, Permission $permission): void
    {
        if ($actor->hasRole(self::ADMIN)) {
            return;
        }

        if ($permission->roles()->exists() || $permission->users()->exists()) {
            abort(403, 'این دسترسی به یک نقش یا کاربر تخصیص یافته است و حذف آن مجاز نیست.');
        }
    }

    /**
     * Issue #1921 — a non-admin may rename only a permission it already holds,
     * and only to a name it already holds (or the same name).
     *
     * Without the first check, renaming a gate it does not hold is a global
     * strip; without the second, delete-then-rename mints a gate the actor
     * never had. `assertNoAmplification()` only covers the second — it compares
     * the submitted set against the current one, so an actor holding the NEW
     * name but not the current one would pass.
     *
     * Do NOT use `hasPermissionTo($name)` for the second check: a brand-new
     * name has no Permission row and Spatie throws PermissionDoesNotExist.
     *
     * @param  string  $name  the validated submitted name
     */
    public static function assertCanMutatePermission(User $actor, Permission $permission, string $name): void
    {
        if ($actor->hasRole(self::ADMIN)) {
            return;
        }

        $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();

        if (! in_array($permission->name, $actorPermissions, true)) {
            abort(403, 'شما نمی‌توانید دسترسی‌ای را تغییر دهید که خودتان ندارید.');
        }

        if ($name !== $permission->name && ! in_array($name, $actorPermissions, true)) {
            abort(403, 'شما نمی‌توانید دسترسی‌ای را به نامی که خودتان ندارید تغییر دهید.');
        }
    }

    /**
     * Issue #1921 (#1663 shape) — the `manage_roles` permission itself can
     * never be deleted, by anyone.
     *
     * It is the only gate on `/permissions` and `/roles`, and the sidebar link
     * hides behind it. Unlike the #1607 ceiling this guard has NO actor bypass:
     * an admin deleting it is the same one-click, in-app-irrecoverable lockout
     * as deleting the `admin` role.
     */
    public static function assertCriticalPermissionSurvives(Permission $permission): void
    {
        if ($permission->name === self::MANAGEMENT_GATE) {
            abort(403, 'مجوز manage_roles حذف‌شدنی نیست؛ بدون آن صفحه‌های نقش و دسترسی برای همیشه بسته می‌شوند.');
        }
    }

    /**
     * Issue #1921 (#1663 shape) — the reserved name may not be renamed AWAY
     * from `manage_roles` either: the gate follows the name, so a rename is
     * the same lockout as a delete.
     */
    public static function assertCriticalPermissionKeepsItsName(Permission $permission, string $name): void
    {
        if ($permission->name === self::MANAGEMENT_GATE && $name !== self::MANAGEMENT_GATE) {
            abort(403, 'نام مجوز manage_roles رزرو شده و قابل تغییر نیست.');
        }
    }

    /**
     * Issue #1663 — the `admin` ROLE itself can never be deleted, by anyone.
     *
     * `role:admin` (backup download + backup page, `routes/web.php:246-259`)
     * exists only while a role NAMED `admin` exists, and no permission
     * represents it, so deleting the role is a one-click lockout with no
     * in-app recovery. Unlike the #1607 ceiling this guard has NO actor bypass.
     */
    public static function assertAdminRoleSurvives(Role $role): void
    {
        if ($role->name === self::ADMIN) {
            abort(403, 'نقش مدیر حذف‌شدنی نیست؛ دسترسی بک‌آپ و ابزارهای مدیریتی فقط از این نقش می‌آید.');
        }
    }

    /**
     * Issue #1663 — the reserved name may not be renamed AWAY from `admin`
     * either: the route gate follows the name, so a rename is the same lockout
     * as a delete. Renaming INTO `admin` is `assertNameIsNotAdmin()`'s job.
     */
    public static function assertAdminRoleKeepsItsName(Role $role, string $name): void
    {
        if ($role->name === self::ADMIN && $name !== self::ADMIN) {
            abort(403, 'نام نقش مدیر رزرو شده و قابل تغییر نیست.');
        }
    }

    /**
     * Issue #1663 — no write may leave zero ACTIVE `admin`-role holders.
     *
     * `$roleIdsAfter === null` means "this user is being deactivated"; an array
     * is the role set the caller is about to `sync()`. Only a write that
     * REMOVES the role from its last holder is refused — a system that already
     * has no admin (every test fixture, a fresh install) must stay writable.
     *
     * MUST run inside a DB transaction: the `FOR UPDATE` is what stops two
     * admins from deactivating each other concurrently. `count()` cannot carry
     * `FOR UPDATE` on Postgres ("FOR UPDATE is not allowed with aggregate
     * functions"), hence `pluck()`.
     *
     * @param  array<int|string>|null  $roleIdsAfter
     */
    public static function assertAdminUserSurvives(User $target, ?array $roleIdsAfter = null): void
    {
        // A soft-deleted account cannot authenticate (EloquentUserProvider reads
        // through the SoftDeletes global scope), so it is not a live admin.
        if ($target->trashed() || ! $target->hasRole(self::ADMIN)) {
            return;
        }

        if ($roleIdsAfter !== null) {
            $adminRoleId = Role::query()->where('name', self::ADMIN)->value('id');

            if ($adminRoleId !== null
                && in_array((int) $adminRoleId, array_map('intval', $roleIdsAfter), true)) {
                return; // the sync keeps the role on this user
            }
        }

        $otherAdmins = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('roles.name', self::ADMIN))
            ->whereKeyNot($target->getKey())
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.id');

        if ($otherAdmins->isEmpty()) {
            abort(403, 'این آخرین کاربر دارای نقش مدیر است؛ با این تغییر هیچ مدیر فعالی باقی نمی‌ماند.');
        }
    }

    private static function assertRoleIsNotAdmin(Role $role): void
    {
        if ($role->name === self::ADMIN) {
            abort(403, 'تنها مدیران می‌توانند نقش مدیر را تغییر دهند.');
        }
    }

    private static function assertNameIsNotAdmin(string $name): void
    {
        if ($name === self::ADMIN) {
            abort(403, 'نام نقش مدیر رزرو شده است.');
        }
    }

    /**
     * @param  array<int, string>  $submitted
     * @param  array<int, string>  $current
     */
    private static function assertNoAmplification(User $actor, array $submitted, array $current): void
    {
        $added = array_diff(
            array_unique($submitted),
            $current,
            $actor->getAllPermissions()->pluck('name')->all(),
        );

        if ($added !== []) {
            abort(403, 'شما نمی‌توانید دسترسی‌ای را اعطا کنید که خودتان ندارید.');
        }
    }
}
