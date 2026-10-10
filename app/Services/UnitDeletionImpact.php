<?php

namespace App\Services;

/**
 * #949 — what deleting one unit would actually destroy.
 *
 * `units` has eight inbound foreign keys. Two are `RESTRICT`
 * (`units_parent_fk`, `tickets_unit_fk`) and stop a delete by themselves. Six
 * are not: `persons.u_id`, `todos.unit_id`, `task_activities.unit_id`,
 * `maintenance_schedules.unit_id` and `daily_reports.unit_id` go NULL, and
 * `user_units` rows are CASCADEd away. The old code guarded the two the
 * database could see and reported that as though the rest were protected.
 *
 * Only five of the six non-RESTRICT tables are counted here. `task_activities`,
 * `daily_reports` and `maintenance_schedules` are omitted on purpose: the last
 * is issue #876's approved contract and the first two are audit and reporting
 * history rather than live operational data. `UnitDeletionService` is where
 * that decision is recorded.
 *
 * This is a value object: it counts, it never queries, and it never renders.
 * The two entry points (`units.index::deleteUnit` and `UnitController::destroy`)
 * format it in their own language — Persian toast vs. English JSON — which is
 * why the labels live in the callers and not here. What lives here is the
 * *identity* of each blocker, so both surfaces name the same tables in the
 * same order.
 *
 * @property-read int $children  child units (`units_parent_fk`, RESTRICT)
 * @property-read int $tickets   tickets (`tickets_unit_fk`, RESTRICT)
 * @property-read int $persons   staff rows (`persons.u_id`, SET NULL)
 * @property-read int $hardware  assets reachable only through those staff
 * @property-read int $todos     todos (`todos.unit_id`, SET NULL)
 * @property-read int $accounts  accounts linked via `user_units` (CASCADE)
 */
final readonly class UnitDeletionImpact
{
    public function __construct(
        public int $children,
        public int $tickets,
        public int $persons,
        public int $hardware,
        public int $todos,
        public int $accounts,
    ) {}

    /**
     * Every non-zero table, in cascade order, keyed by identity.
     *
     * An empty array is the ONLY statement "this delete is safe" — there is no
     * second, weaker answer.
     *
     * Both entry points do check `children` before calling `impact()`, because
     * their children messages are part of a pinned contract
     * (`'Cannot delete unit with children.'` is asserted by
     * `UnitApiTest`). So in production `children` is always 0 here: it is a
     * completeness backstop, not a live branch, and its label in the callers'
     * maps is defensive for the same reason.
     *
     * @return array<string, int>
     */
    public function blockers(): array
    {
        return array_filter([
            'children' => $this->children,
            'tickets' => $this->tickets,
            'persons' => $this->persons,
            'hardware' => $this->hardware,
            'todos' => $this->todos,
            'accounts' => $this->accounts,
        ], fn (int $count): bool => $count > 0);
    }

    public function isClear(): bool
    {
        return $this->blockers() === [];
    }
}
