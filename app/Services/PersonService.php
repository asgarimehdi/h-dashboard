<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * #1896: the single write path for a person update that may transfer units.
 *
 * `persons.u_id` and the linked user's `user_units.is_primary` are one
 * invariant: after a transfer both point at the new unit, and both writes
 * commit or roll back together. The API controller did a bare
 * `$person->update()`, so `primaryUnit()` kept the old unit and
 * `TodoController`'s fallback put new todos there.
 *
 * No-op contract: when the payload does not change `u_id` (absent or equal),
 * nothing is written to `user_units` and no access cache is invalidated.
 */
final class PersonService
{
    /**
     * @param  array<string, mixed>  $attributes  validated person attributes
     */
    public function update(Person $person, array $attributes): Person
    {
        DB::transaction(function () use ($person, $attributes): void {
            $person->update($attributes);

            if (! $person->wasChanged('u_id')) {
                return;
            }

            // setPrimaryUnit() keeps the one-primary invariant (#1803); its own
            // transaction nests as a savepoint here.
            $person->user?->setPrimaryUnit((int) $person->u_id);
        });

        // Issue #1277 (see users/index.blade.php): AFTER commit. Person::saved
        // bumps `unit_hierarchy` inside the transaction, so a concurrent reader
        // can cache the pre-commit pivot under the new version; this second bump
        // makes that entry unreachable.
        if ($person->wasChanged('u_id') && ($user = $person->user)) {
            app(AccessService::class)->clearCache($user);
        }

        return $person;
    }
}
