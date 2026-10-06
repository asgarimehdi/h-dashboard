<?php

namespace App\Rules;

use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The destination unit of a ticket — for both `tickets.create::saveTicket()`
 * and the two inbox forwarding paths (`forward()` and `submitAction()`).
 *
 * Issue #818: `exists:units,id` was the whole target-side check, so a ticket
 * could be routed to any unit in the system — including one that is
 * `can_receive_tickets = false` or `is_active = false`, and including a unit
 * the caller has no relation to. Forwarding is legitimately cross-unit, so the
 * constraint is *eligibility*, not accessibility.
 *
 * The decision (issue #818, correction 4 — "extract the dropdown's rule" and
 * "require `is_active`" were two different rules and only one was chosen):
 * a destination must be BOTH `can_receive_tickets` AND `is_active`, which is
 * the stricter of the two pickers and the only rule every surface can share.
 * `tickets.create`'s picker already filtered both; the inbox dropdown filtered
 * only `can_receive_tickets`, so it is aligned to this rule.
 */
class TicketTargetUnit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absence is `required`'s job, not ours — do not double-report it.
        if ($value === null || $value === '') {
            return;
        }

        $unit = Unit::query()->whereKey($value)->first();

        if (! $unit) {
            $fail('واحد انتخابی معتبر نیست.');

            return;
        }

        if (! $unit->can_receive_tickets) {
            $fail('این واحد امکان دریافت تیکت ندارد.');

            return;
        }

        if (! $unit->is_active) {
            $fail('این واحد غیرفعال است.');
        }
    }
}
