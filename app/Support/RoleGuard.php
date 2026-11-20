<?php

namespace App\Support;

use App\Models\User;
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
 * an `admin`-role holder bypasses the whole guard.
 */
final class RoleGuard
{
    public const ADMIN = 'admin';

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
