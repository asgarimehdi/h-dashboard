<?php

namespace App\Support;

/**
 * Single source of truth for the person write rules (#1745).
 *
 * The API and the Livewire form write the same nine fields, but the rules were
 * duplicated and had drifted: the API validated eight and never validated
 * `phone`, so `Person::create($validated)` / `$person->update($validated)`
 * silently dropped a submitted phone behind a 201/200 that claimed success.
 *
 * Three deliberate asymmetries live here, not in the callers — do not "fix" them:
 *  - `n_code`: the web form validates `unique:persons,n_code,<editingId>` and
 *    then refuses a changed n_code separately (#959); the API `store()` uses a
 *    plain `unique` and checks the unit scope AFTER validation (#532). The API
 *    `update()` carries NO `n_code` rule at all — immutability is the #959
 *    check in the controller, and a `unique` rule there would 422 the normal
 *    full-payload PUT that echoes the unchanged value.
 *  - `required`: the web form always requires every field; the API `update()`
 *    uses `sometimes|required` so a partial PUT stays valid (`$required`).
 *  - `phone`: free text, `nullable|string|max:20` on both paths (#738), never
 *    `sometimes|required` — `null` must still clear it on update.
 *
 * Not covered on purpose: `birth_date`, `hire_date` and `status` are fillable
 * but neither write path validates or writes them (`status` comes from the
 * column default). `PersonImport` keeps its own row-level validation; if it
 * ever joins this class, record that decision here.
 */
class PersonRules
{
    /**
     * Rules for `Api\PersonController::store()` / `update()`.
     *
     * @param  bool  $required  true = store, false = update (sometimes|required, no n_code)
     * @return array<string, string>
     */
    public static function apiRules(bool $required = true): array
    {
        if (! $required) {
            return self::commonRules(false);
        }

        return [
            'n_code' => 'required|string|size:10|unique:persons,n_code',
        ] + self::commonRules(true);
    }

    /**
     * Rules for the Livewire `savePerson()` method.
     *
     * Takes no accessible-unit ids: the person web path's `n_code` rule is a
     * global `unique` (not the scope-aware `exists` `HardwareRules::webRules()`
     * uses) and the unit scope is enforced by explicit checks after validation
     * (#805). Passing ids here would invite that behaviour to be swapped
     * silently — and `savePerson()` does not hold them at validation time.
     *
     * @param  int|null  $editingId  the record under edit, null on create
     * @return array<string, string>
     */
    public static function webRules(?int $editingId = null): array
    {
        return [
            'n_code' => 'required|string|size:10|unique:persons,n_code,'.($editingId ?: 'NULL'),
        ] + self::commonRules(true);
    }

    /**
     * @return array<string, string>
     */
    private static function commonRules(bool $required): array
    {
        $prefix = $required ? 'required' : 'sometimes|required';

        return [
            'f_name' => $prefix.'|string|max:255',
            'l_name' => $prefix.'|string|max:255',
            't_id' => $prefix.'|exists:tahsils,id',
            'e_id' => $prefix.'|exists:estekhdams,id',
            's_id' => $prefix.'|exists:semats,id',
            'r_id' => $prefix.'|exists:radifs,id',
            'u_id' => $prefix.'|exists:units,id',
            // #738: free-text phone, optional, simple max length — no format
            // constraint (see the migration's docblock).
            'phone' => 'nullable|string|max:20',
        ];
    }
}
