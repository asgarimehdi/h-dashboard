<?php

namespace App\Http\Requests;

use App\Exceptions\UnitNotAccessibleException;
use App\Services\AccessService;
use Illuminate\Foundation\Http\FormRequest;

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
     *
     * Throws UnitNotAccessibleException (rendered as a JSON 403) when the unit
     * is out of scope.
     */
    public function assertAccessibleUnit(int $unitId): void
    {
        if (! in_array($unitId, $this->accessibleIds(), true)) {
            throw new UnitNotAccessibleException;
        }
    }
}
