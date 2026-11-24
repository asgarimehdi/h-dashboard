<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for the hardware write rules (#1730).
 *
 * The API and the Livewire form both write the same 19 columns, but only the
 * API validated them: the web path validated `n_code`/`pc_name`/`comments` and
 * wrote the other 16 unchecked (an over-long value was stored silently, and an
 * unparsable `clean_at` threw Carbon\InvalidFormatException as a 500).
 *
 * Two deliberate asymmetries live here, not in the callers — do not "fix" them:
 *  - `n_code`: the API uses a plain `exists` and checks the unit scope after
 *    validation (403 with a JSON message); the web form uses the scope-aware
 *    `exists` (fail-closed). `apiRules()`/`webRules()` keep them apart so no
 *    caller can drop the scope by accident.
 *  - `shutdown`: the web create form can set it (the checkbox exists); API
 *    `store` cannot (its validated payload never has the key, so the DB
 *    default applies).
 */
class HardwareRules
{
    /**
     * Rules for `Api\HardwareController` (unchanged behaviour).
     *
     * @param  bool  $required  true = store, false = update (sometimes|required)
     * @param  bool  $includeShutdown  true = include shutdown boolean (update only)
     * @return array<string, string>
     */
    public static function apiRules(bool $required = true, bool $includeShutdown = false): array
    {
        $nCode = $required ? 'required|string|exists:persons,n_code' : 'sometimes|required|string|exists:persons,n_code';

        return ['n_code' => $nCode] + self::commonRules($required, $includeShutdown);
    }

    /**
     * Rules for the Livewire create/update methods. The `n_code` rule is
     * scope-aware: an empty id list compiles to `0 = 1` (in scope of nothing).
     *
     * @param  list<int>  $accessibleUnitIds
     * @return array<string, mixed>
     */
    public static function webRules(array $accessibleUnitIds, bool $includeShutdown = true): array
    {
        return [
            'n_code' => [
                'required',
                'string',
                Rule::exists('persons', 'n_code')->where(static fn (Builder $q) => $q->whereIn('u_id', $accessibleUnitIds)),
            ],
        ] + self::commonRules(true, $includeShutdown);
    }

    /**
     * @return array<string, string>
     */
    private static function commonRules(bool $required, bool $includeShutdown): array
    {
        $rules = [
            'pc_name' => $required ? 'required|string|max:255' : 'sometimes|required|string|max:255',
            'type' => 'nullable|string|max:50',
            'os' => 'nullable|string|max:100',
            'ip_valid' => 'nullable|string|max:45',
            'ip_local' => 'nullable|string|max:45',
            'mac' => 'nullable|string|max:17',
            'net_type' => 'nullable|string|max:50',
            'switch' => 'nullable|string|max:100',
            'port' => 'nullable|string|max:50',
            'vlan' => 'nullable|string|max:50',
            'motherboard' => 'nullable|string|max:100',
            'cpu' => 'nullable|string|max:100',
            'ram' => 'nullable|string|max:50',
            'hdd' => 'nullable|string|max:100',
            'comments' => 'nullable|string|max:20000',
            'mark' => 'boolean',
            'clean_at' => 'nullable|date',
        ];

        if ($includeShutdown) {
            $rules['shutdown'] = 'boolean';
        }

        return $rules;
    }
}
