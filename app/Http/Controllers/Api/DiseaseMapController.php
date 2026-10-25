<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiseasePeriodRequest;
use App\Models\Disease;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\Disease\DiseaseAggregationService;
use Illuminate\Http\JsonResponse;

/**
 * Aggregate-only disease endpoints for the map (spec §6, issue #1245).
 *
 * The payloads carry counts, rates and organizational names — never a patient
 * identifier, name or dynamic field value (decision 18).
 */
class DiseaseMapController extends Controller
{
    public function __construct(
        protected CacheInvalidationServiceInterface $cache
    ) {}

    /**
     * The catalogue the map legend can offer.
     *
     * Active rows only (decision 16/§3.2). Deliberately uncached: twenty rows
     * cost nothing, and a cache entry here would only add a key to invalidate.
     */
    public function diseases(): JsonResponse
    {
        return response()->json(
            Disease::query()
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('id')
                ->get(['id', 'name_fa', 'slug'])
        );
    }

    /**
     * Scope-wide counts for the whole period, without an envelope (spec §6).
     *
     * Deliberately uncached until the `disease_maps` invalidation is complete
     * (Task 6): a cached range with no bump would serve stale counts.
     */
    public function stats(DiseasePeriodRequest $request, DiseaseAggregationService $aggregation): JsonResponse
    {
        $ids = $request->accessibleIds();

        if ($ids === []) {
            return response()->json(['cases' => 0, 'population' => null, 'rate' => null]);
        }

        ['from' => $from, 'to' => $to] = $request->periodBounds();

        return response()->json(
            $aggregation->scopeStats($request->integer('disease'), $ids, $from, $to)
        );
    }
}
