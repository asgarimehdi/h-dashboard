<?php

namespace App\Http\Requests;

use App\Support\Bbox;
use Closure;

/**
 * The map endpoint's extra parameters on top of the shared period (issue #1245).
 *
 * `zoom` defaults to 0 (decision ج): the aggregate view is cheaper and safer,
 * and the client always sends its real zoom anyway. `bbox` is validated by the
 * same normaliser the cache key uses, so an invalid viewport is a 422 rather
 * than a silently ignored filter.
 */
class DiseaseMapRequest extends DiseasePeriodRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'zoom' => ['nullable', 'integer', 'min:0', 'max:22'],
            'bbox' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Bbox::normalize(is_string($value) ? $value : null) === null) {
                        $fail('محدوده جغرافیایی معتبر نیست.');
                    }
                },
            ],
        ]);
    }

    public function zoom(): int
    {
        $zoom = $this->input('zoom');

        return is_numeric($zoom) ? (int) $zoom : 0;
    }

    /**
     * @return array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null
     */
    public function bboxOrNull(): ?array
    {
        $raw = $this->input('bbox');

        return Bbox::normalize(is_string($raw) ? $raw : null);
    }

    public function bboxKey(): string
    {
        $box = $this->bboxOrNull();

        return $box === null ? 'none' : Bbox::toKey($box);
    }
}
