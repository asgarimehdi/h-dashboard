<?php

namespace App\Http\Requests;

use App\Support\Bbox;
use Closure;

/**
 * The GIS map endpoints' parameters on top of the shared unit scope.
 *
 * A malformed `bbox` is a 422, never a silently ignored filter (#1721). The
 * validation uses the same normaliser as the cache key (#1245), so the two
 * can never disagree about what a viewport means.
 */
class GisRequest extends UnitScopedRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // #1805 — same 0..22 contract as DiseaseMapRequest; out-of-range
            // must be a 422, not a 500 from pow()/raw SQL.
            'zoom' => ['nullable', 'integer', 'min:0', 'max:22'],
            // #1811: `type` (hardware) and `priority`/`status` (tickets) are
            // passed to a `string` normaliser or a typed `where()`. An array
            // value must be a 422 instead of a TypeError/coerced 200.
            'type' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string'],
            'bbox' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Bbox::normalize(is_string($value) ? $value : null) === null) {
                        $fail('محدوده جغرافیایی معتبر نیست.');
                    }
                },
            ],
        ];
    }
}
