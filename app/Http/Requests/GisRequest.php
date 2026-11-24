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
