<?php

namespace Tests\Support\Fakes;

use App\Models\User;
use App\Services\AccessService;

/**
 * AccessService double that hands out a caller-controlled id ORDER.
 *
 * `Unit::descendantIds()` runs a recursive CTE with no `ORDER BY`, and
 * `AccessService` caches the result array as it comes back — so the order in
 * which a real scope arrives is not stable, and two users with the same scope
 * can legitimately receive the same ids in a different order.
 *
 * The double lets a test reproduce that: the same set of ids, in two different
 * orders, across two requests.
 */
class OrderedScopeAccessService extends AccessService
{
    /**
     * The ids this scope resolves to, in the order they are handed out.
     *
     * @var array<int>
     */
    public array $ids = [];

    /**
     * @return array<int>
     */
    public function accessibleUnitIds(?User $user = null): array
    {
        return $this->ids;
    }
}
