<?php

namespace App\Services;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * #949 steps 2–3 — the impact report that has to run before any unit delete.
 *
 * Eight counts, one service, two callers (`units.index::deleteUnit` and
 * `UnitController::destroy`). It exists so the Livewire list and the JSON API
 * cannot drift apart on what "safe to delete" means — which is exactly how the
 * API became strictly weaker than the UI while both claimed to guard deletes.
 *
 * Three `ON DELETE SET NULL` tables are deliberately NOT counted:
 * `maintenance_schedules` (issue #876's approved contract — counting it here
 * would take that issue's decision away from it), plus `daily_reports` and
 * `task_activities`, which are reporting and audit history rather than live
 * operational data. The FK survey that started this found eleven inbound
 * constraints and six non-RESTRICT ones; three of those six are counted below.
 * The two #1791 counts (`disease_cases`, `unit_populations`) are themselves
 * `RESTRICT` and stop the delete at the database, so they are counted here to
 * refuse it with a reason instead.
 */
class UnitDeletionService
{
    public function impact(Unit $unit): UnitDeletionImpact
    {
        return new UnitDeletionImpact(
            children: $unit->children()->count(),
            tickets: Ticket::query()->where('unit_id', $unit->id)->count(),
            // RESTRICT. `disease_cases` reaches `units` twice (`unit_id` is the
            // recording unit, `subject_unit_id` the patient's unit); a row
            // matching both must count once, so this is one grouped query, not
            // two counts added together.
            diseaseCases: DB::table('disease_cases')
                ->where(function ($q) use ($unit): void {
                    $q->where('unit_id', $unit->id)
                        ->orWhere('subject_unit_id', $unit->id);
                })
                ->count(),
            // RESTRICT. Only leaf units own a row (unique on unit + year).
            populations: DB::table('unit_populations')
                ->where('unit_id', $unit->id)
                ->count(),
            persons: Person::query()->where('u_id', $unit->id)->count(),
            // Hardware has no unit column: it reaches a unit through the person
            // who owns it (`hardwares.n_code` → `persons.n_code`). Deleting the
            // unit leaves the rows intact and makes them unreachable from every
            // scope, because `applyOrgScope()` matches `persons.u_id` and that
            // has just been set to NULL.
            hardware: Hardware::query()
                ->whereHas('person', fn ($q) => $q->where('u_id', $unit->id))
                ->count(),
            // SET NULL. #838 and #917 spent two issues deciding who owns a
            // null-unit todo; a delete must not manufacture more of them.
            todos: Todo::query()->where('unit_id', $unit->id)->count(),
            // CASCADE — the only one of the six that destroys the row outright.
            // `user_units` has a unique index on (user_id, unit_id), so a plain
            // count is already the number of distinct accounts.
            accounts: DB::table('user_units')->where('unit_id', $unit->id)->count(),
        );
    }
}
