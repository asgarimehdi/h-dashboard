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
 * Six counts, one service, two callers (`units.index::deleteUnit` and
 * `UnitController::destroy`). It exists so the Livewire list and the JSON API
 * cannot drift apart on what "safe to delete" means — which is exactly how the
 * API became strictly weaker than the UI while both claimed to guard deletes.
 *
 * `maintenance_schedules` is deliberately NOT counted. It is the other
 * `ON DELETE SET NULL` half of this same cascade and it is issue #876; adding
 * it here would silently take that issue's approved contract away from it.
 */
class UnitDeletionService
{
    public function impact(Unit $unit): UnitDeletionImpact
    {
        return new UnitDeletionImpact(
            children: $unit->children()->count(),
            tickets: Ticket::query()->where('unit_id', $unit->id)->count(),
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
