<?php

namespace App\Http\Requests;

use App\Services\AccessService;
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
     * Assert the given unit ID is within the caller's accessible scope.
     * Returns 403 JSON response if not authorized.
     */
    public function assertAccessibleUnit(int $unitId): JsonResponse|true
    {
        if (! in_array($unitId, $this->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return true;
    }
}
