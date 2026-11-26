<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Issue #1864 — creating a unit at the hierarchy root, or moving one there, is a
 * top-level action. A root unit has no scope anchor: it is visible org-wide and
 * can divert tickets away from the real hierarchy, so the unit-scope checks that
 * guard every other write cannot express it.
 *
 * The rule is the `admin` ROLE, not a permission: `admin` is the app's only
 * top-level role and the one role whose power no permission represents
 * (RoleGuard). A new permission would have to be seeded in every deployment
 * first — Spatie answers `false` for an unknown permission, so a missed seed
 * would 403 every non-admin silently.
 *
 * Callers: Api\UnitController::store()/update() and units.index::saveUnit().
 * The Livewire component calls this by FQCN on purpose — a `use` import there
 * shifts the anonymous class line and invalidates its line-keyed PHPStan
 * baseline entries.
 */
final class RootUnitPrivilege
{
    public static function allows(?Authenticatable $user): bool
    {
        return $user instanceof User && $user->hasRole(RoleGuard::ADMIN);
    }
}
