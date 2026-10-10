<?php

namespace App\Services;

/**
 * #949 — what deleting one unit would actually destroy.
 *
 * `units` has eight inbound foreign keys. Only the two `RESTRICT` ones
 * (`units_parent_fk`, `tickets_unit_fk`) stop a delete on their own; the other
 * six obey the database silently — `persons.u_id`, `todos.unit_id` and
 * `task_activities.unit_id` go NULL, `user_units` rows are CASCADEd away. The
 * old code guarded the two the database could see and reported that as though
 * the rest were protected.
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
     * second, weaker answer. Both entry points render this map directly rather
     * than re-deciding which tables matter.
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
