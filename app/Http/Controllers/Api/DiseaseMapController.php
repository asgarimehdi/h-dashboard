<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiseaseMapRequest;
use App\Http\Requests\DiseasePeriodRequest;
use App\Models\Disease;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\Disease\DiseaseAggregationService;
use App\Services\Disease\DiseaseMapService;
use App\Support\ScopeCacheKey;
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
     * Cached under `disease_maps` for 60 minutes; every disease-domain model
     * write bumps the namespace (Task 6), so a stale range is unreachable
     * rather than served.
     */
    public function stats(DiseasePeriodRequest $request, DiseaseAggregationService $aggregation): JsonResponse
    {
        $ids = $request->accessibleIds();

        if ($ids === []) {
            return response()->json(['cases' => 0, 'population' => null, 'rate' => null]);
        }

        ['from' => $from, 'to' => $to] = $request->periodBounds();

        /** @var array{cases: int, population: int|null, rate: float|null} $stats */
        $stats = $this->cache->remember(
            'disease_maps',
            ScopeCacheKey::hash($ids),
            fn () => $aggregation->scopeStats($request->integer('disease'), $ids, $from, $to),
            60,
            [
                'v' => 1,
                'kind' => 'stats',
                'disease' => $request->integer('disease'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        );

        return response()->json($stats);
    }

    /**
     * The GeoJSON layer: region choropleth below the zoom switch, unit markers
     * above it. Cached under `disease_maps`; the mode and bbox are part of the
     * key so a viewport never serves another viewport's payload.
     */
    public function map(DiseaseMapRequest $request, DiseaseMapService $service): JsonResponse
    {
        $ids = $request->accessibleIds();

        if ($ids === []) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
                'meta' => [
                    'level' => 'none',
                    'zoom_switch' => DiseaseMapService::ZOOM_SWITCH,
                    'classes' => 0,
                    'breaks' => [],
                    'fallback_provinces' => 0,
                    'unmapped_cases' => 0,
                ],
            ]);
        }

        ['from' => $from, 'to' => $to] = $request->periodBounds();

        $extra = [
            'v' => 1,
            'kind' => 'map',
            'disease' => $request->integer('disease'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'mode' => $request->zoom() >= DiseaseMapService::ZOOM_SWITCH ? 'unit' : 'region',
            'bbox' => $request->bboxKey(),
        ];

        /** @var array{features: list<array<string, mixed>>, meta: array<string, mixed>} $payload */
        $payload = $this->cache->remember(
            'disease_maps',
            ScopeCacheKey::hash($ids),
            fn () => $service->map(
                $request->integer('disease'),
                $ids,
                $from,
                $to,
                $request->zoom(),
                $request->bboxOrNull()
            ),
            60,
            $extra,
        );

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $payload['features'],
            'meta' => $payload['meta'],
        ]);
    }
}
