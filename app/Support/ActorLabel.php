<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Display label for the actor behind a ticket / activity row (issue #906).
 *
 * `User` soft-deletes, so EVERY `$model->user` is nullable in practice —
 * regardless of whether the foreign key itself is nullable. Two independent
 * producers reach that state, and they must not be conflated:
 *
 *  - `user_id` is genuinely null → a system-generated row, e.g. the ticket
 *    `maintenance:generate-due` writes for an overdue maintenance schedule.
 *  - `user_id` still points at a row, but that `User` is soft-deleted → a real
 *    person whose account was deactivated by an admin.
 *
 * A deactivated account labelled "سیستم" would read as a scheduled task, so the
 * two get distinct labels. To tell them apart the relation must be eager-loaded
 * with soft-deleted users included — without it both cases resolve to `null`
 * and are indistinguishable.
 *
 * The `User` case is checked first, so a null-safe fallback alone can never
 * swallow the deactivated-user wording.
 */
final class ActorLabel
{
    /** Label for a row with no creator at all (a system-generated ticket). */
    public const SYSTEM = 'سیستم';

    /** Label for a row whose creator account was soft-deleted. */
    public const INACTIVE_USER = 'کاربر غیرفعال';

    /**
     * The full name to show, or a label when there is no resolvable actor.
     *
     * Returns '' only when the actor exists and simply has no linked person
     * record, which keeps the previous (blank) rendering for that case rather
     * than inventing a label the UI has never shown.
     */
    public static function for(?User $user): string
    {
        if ($user === null) {
            return self::SYSTEM;
        }

        if ($user->trashed()) {
            return self::INACTIVE_USER;
        }

        $person = $user->person;

        return $person ? trim($person->f_name.' '.$person->l_name) : '';
    }

    /**
     * Eager-load constraint for a `user` relation, used as:
     *   `Ticket::with(['user' => fn ($q) => ActorLabel::eagerLoadActor($q)])`
     *
     * Keeps soft-deleted users in the result — equivalent to `withTrashed()`,
     * which `SoftDeletingScope::addWithTrashed()` registers as the macro
     * `$builder->withoutGlobalScope($this)`. The macro is invisible to PHPStan
     * (registered at runtime, never declared on `Eloquent\Builder`) and this
     * repo runs PHPStan at level 6 in CI, so the declared form is used instead.
     * `withoutGlobalScope()` accepts an object or a class-string, so the two are
     * equivalent.
     *
     * Every call is a STATEMENT, never chained: `Eloquent\Builder` carries a
     * one-way `@mixin Query\Builder`, so chaining off its return value re-types
     * the rest of the chain as `Query\Builder`, on which `select()` and `with()`
     * are undefined.
     *
     * The closure passed to `with()` receives a `Relation`, not a `Builder`
     * (`Builder::eagerLoadRelation()` calls `$constraints($relation)`), which is
     * why the parameter is typed `Relation` and why the repo's other
     * `with()`/relationship closures stay untyped.
     *
     * @param  Relation<*, *, *>  $relation
     */
    public static function eagerLoadActor(Relation $relation): void
    {
        $query = $relation->getQuery();

        $query->withoutGlobalScope(SoftDeletingScope::class);

        // `deleted_at` is NOT optional: `ActorLabel::for()` asks `trashed()`,
        // which returns "not deleted" whenever the column was not selected — so
        // a select() without it renders a soft-deleted creator under their real
        // name on one surface and «کاربر غیرفعال» on the next.
        $query->select(['id', 'n_code', 'deleted_at']);
        $query->with('person:f_name,l_name');
    }
}
