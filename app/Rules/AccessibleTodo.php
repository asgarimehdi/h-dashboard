<?php

namespace App\Rules;

use App\Models\Todo;
use App\Services\AccessService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * A `todos.id` submitted by `tickets.create::saveTicket()`.
 *
 * Issue #847: `task_id` was written straight from the client into
 * `tickets.task_id`, with no validation of any kind. `unit_id` was already
 * gated by `TicketTargetUnit` (#818), so `task_id` was the last input on the
 * form without even an existence check — which meant a missing id raised
 * `QueryException SQLSTATE[23503]` instead of a validation message, and any
 * real id linked the ticket to a todo in a unit the creator cannot reach. That
 * link then leaked the todo's title through three read surfaces and let a
 * `unit_manager` force `is_completed = true` on it.
 *
 * The unit question (the product decision recorded on #847, option ج):
 * `tickets.task_id` must point at a todo inside the **creator's** scope —
 * `AccessService::accessibleUnitIds()` — and not at the destination unit.
 * Unlike `unit_id`, the related task is not routed to the destination; an
 * explicit pick yields the creator's unit, and only the auto-create branch
 * (see the component) targets the destination.
 *
 * `is_completed = false` is part of the rule, not an extra nicety: the picker
 * only ever offered open todos, so accepting a completed one was always a
 * server-side/UI disagreement, and a completed task would immediately satisfy
 * the "all tickets of this task are done" condition in `submitAction()`.
 *
 * A **null-unit** todo is treated as in-scope when it belongs to the actor,
 * which is the creator-ownership contract of issue #838.
 *
 * The scope predicate is an unconditional `whereIn` on purpose: an empty scope
 * must compile to `0 = 1` and fail closed. Never guard it with
 * `->when($ids, fn ($q) => $q->whereIn(...))` — an empty array is falsy, the
 * callback is skipped, and the row is accepted (the #819 leak).
 */
class AccessibleTodo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absence is `nullable`'s job — an unlinked ticket is legitimate.
        if ($value === null || $value === '') {
            return;
        }

        $todo = Todo::query()
            ->with('unit')
            ->whereKey($value)
            // Unconditional: see the class docblock. `[]` => `0 = 1`.
            ->where(fn ($q) => $q->whereIn('unit_id', $this->accessibleIds())
                ->orWhere(fn ($q) => $q->whereNull('unit_id')->where('user_id', Auth::id())))
            ->first();

        if (! $todo) {
            $fail('وظیفه انتخابی معتبر نیست.');

            return;
        }

        if ($todo->is_completed) {
            $fail('وظیفه انتخابی تکمیل شده است.');
        }
    }

    /**
     * @return array<int>
     */
    private function accessibleIds(): array
    {
        return app(AccessService::class)->accessibleUnitIds();
    }
}
