<?php

namespace App\Rules;

use App\Models\Unit;
use App\Services\AccessService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A `units.id` submitted as the disease case's subject unit (#1244).
 *
 * The unit picker writes the model straight from the client, so the value is
 * attacker-controlled by definition. This rule is the server-side re-check:
 * the unit must exist, be active, and fall inside the actor's
 * `accessibleUnitIds()`.
 *
 * The `whereIn` is unconditional on purpose — an empty scope is "in scope of
 * nothing" and must compile to `0 = 1`, never to "no filter" (AGENTS.md
 * fail-closed rule).
 */
final class AccessibleUnit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unitId = is_numeric($value) ? (int) $value : 0;

        if ($unitId <= 0) {
            $fail('انتخاب واحد الزامی است.');

            return;
        }

        $exists = Unit::query()
            ->whereKey($unitId)
            ->where('is_active', true)
            ->whereIn('id', app(AccessService::class)->accessibleUnitIds())
            ->exists();

        if (! $exists) {
            $fail('واحد انتخاب‌شده در محدودهٔ دسترسی شما نیست.');
        }
    }
}
