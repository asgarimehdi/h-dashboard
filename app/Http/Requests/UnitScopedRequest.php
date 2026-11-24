<?php

namespace App\Http\Requests;

use App\Services\AccessService;
use App\Support\Bbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

class UnitScopedRequest extends FormRequest
{
    protected ?array $accessibleIds = null;

    public function authorize(): bool
    {
        return true; // Authorization logic delegated to methods
    }

    public function accessibleIds(): array
    {
        return $this->accessibleIds ??= app(AccessService::class)
            ->accessibleUnitIds($this->user());
    }

    /**
     * The parsed `bbox` viewport, or null when the parameter is absent.
     *
     * Malformed values never reach this helper from a validated request (#1721);
     * callers that do not validate still get null rather than a bogus box.
     *
     * @return array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null
     */
    public function bboxOrNull(): ?array
    {
        $raw = $this->input('bbox');

        return Bbox::normalize(is_string($raw) ? $raw : null);
    }

    /**
     * Assert the given unit ID is within the caller's accessible scope.
     * Returns 403 JSON response if not authorized.
     */
    public function assertAccessibleUnit(int $unitId): JsonResponse|true
    {
        if (! in_array($unitId, $this->accessibleIds())) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        return true;
    }
}
