<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Unit;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class GisController extends Controller
{
    public function __construct(
        protected CacheInvalidationServiceInterface $cache
    ) {}

    /**
     * Apply bbox spatial filter using lat/lng columns (works without geom column).
     */
    protected function applyBbox($query, UnitScopedRequest $request)
    {
        if (! $request->filled('bbox')) {
            return $query;
        }

        $bbox = explode(',', $request->bbox);
        if (count($bbox) !== 4) {
            return $query;
        }

        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);

        $query->whereBetween('lat', [$minLat, $maxLat])
            ->whereBetween('lng', [$minLon, $maxLon]);

        return $query;
    }

    /**
     * Bbox filter for queries with prefixed/aliased columns (JOINs) — Issue #404.
     */
    protected function applyBboxAliased($query, UnitScopedRequest $request)
    {
        if (! $request->filled('bbox')) {
            return $query;
        }

        $bbox = explode(',', $request->bbox);
        if (count($bbox) !== 4) {
            return $query;
        }

        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);

        $query->whereBetween('units.lat', [$minLat, $maxLat])
            ->whereBetween('units.lng', [$minLon, $maxLon]);

        return $query;
    }

    /**
     * Build a normalized bbox string for cache keys (4 decimal places ≈ 11m precision).
     * Fixes Issue #456: GIS bbox rounding caused incorrect map data display.
     */
    protected function normalizedBbox(UnitScopedRequest $request): string
    {
        if (! $request->filled('bbox')) {
            return 'all';
        }

        $bbox = explode(',', $request->bbox);
        if (count($bbox) !== 4) {
            return 'all';
        }

        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);

        return implode(',', [
            round($minLon, 4),
            round($minLat, 4),
            round($maxLon, 4),
            round($maxLat, 4),
        ]);
    }

    /**
     * Build a cache key for GIS endpoints via the unified service.
     */
    protected function gisCacheKey(string $endpoint, array $accessibleIds, string $bbox, array $extra = []): string
    {
        $scopeHash = md5(implode(',', $accessibleIds));
        $extraHash = empty($extra) ? 'none' : md5(serialize($extra));

        return $this->cache->cacheKey("gis_{$endpoint}", $scopeHash, "{$bbox}:{$extraHash}");
    }

    /**
     * Get units as GeoJSON FeatureCollection within spatial bounds.
     * Query params: bbox (minLon,minLat,maxLon,maxLat)
     */
    public function units(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $bbox = $this->normalizedBbox($request);
        $cacheKey = $this->gisCacheKey('units', $accessibleIds, $bbox);

        $features = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
            $query = Unit::whereIn('id', $accessibleIds)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->select('id', 'name', 'unit_type_id', 'parent_id', 'region_id', 'lat', 'lng');

            $this->applyBbox($query, $request);

            $units = $query->limit(1000)->get();

            return $units->map(function ($unit) {
                return [
                    'type' => 'Feature',
                    'id' => $unit->id,
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $unit->lng, (float) $unit->lat],
                    ],
                    'properties' => [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'unit_type_id' => $unit->unit_type_id,
                        'parent_id' => $unit->parent_id,
                        'region_id' => $unit->region_id,
                        'lat' => (float) $unit->lat,
                        'lng' => (float) $unit->lng,
                    ],
                ];
            })->values()->all();
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Get hardware as GeoJSON FeatureCollection with parent unit location.
     * Query params: bbox, type, shutdown, mark
     */
    public function hardware(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $bbox = $this->normalizedBbox($request);
        $extra = array_filter([
            'type' => $request->filled('type') ? $request->type : null,
            'shutdown' => $request->filled('shutdown') ? $request->boolean('shutdown') : null,
            'mark' => $request->filled('mark') ? $request->boolean('mark') : null,
        ], fn ($v) => $v !== null); // only remove null, keep false/0
        $cacheKey = $this->gisCacheKey('hardware', $accessibleIds, $bbox, $extra);

        $features = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
            // Direct JOIN instead of eager-loading 3 model layers — collects only the
            // columns the GeoJSON needs (unit name/coords + person name) (#404)
            $query = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
                ->join('units', 'persons.u_id', '=', 'units.id')
                ->whereIn('persons.u_id', $accessibleIds)
                ->whereNotNull('units.lat')->whereNotNull('units.lng')
                ->select(
                    'hardwares.id',
                    'hardwares.n_code',
                    'hardwares.pc_name',
                    'hardwares.type',
                    'hardwares.os',
                    'hardwares.cpu',
                    'hardwares.ram',
                    'hardwares.hdd',
                    'hardwares.shutdown',
                    'hardwares.mark',
                    'units.id as unit_id',
                    'units.name as unit_name',
                    'units.lat as unit_lat',
                    'units.lng as unit_lng',
                    'persons.f_name',
                    'persons.l_name'
                );

            $this->applyBboxAliased($query, $request);

            if ($request->filled('type')) {
                $query->where('hardwares.type', 'LIKE', "%{$request->type}%");
            }
            if ($request->filled('shutdown')) {
                $query->where('hardwares.shutdown', $request->boolean('shutdown'));
            }
            if ($request->filled('mark')) {
                $query->where('hardwares.mark', $request->boolean('mark'));
            }

            $hardware = $query->limit(1000)->get();

            return $hardware->map(function ($hw) {
                return [
                    'type' => 'Feature',
                    'id' => $hw->id,
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $hw->unit_lng, (float) $hw->unit_lat],
                    ],
                    'properties' => [
                        'id' => $hw->id,
                        'n_code' => $hw->n_code,
                        'pc_name' => $hw->pc_name,
                        'type' => $hw->type,
                        'os' => $hw->os,
                        'cpu' => $hw->cpu,
                        'ram' => $hw->ram,
                        'hdd' => $hw->hdd,
                        'shutdown' => (bool) $hw->shutdown,
                        'mark' => (bool) $hw->mark,
                        'unit' => [
                            'id' => $hw->unit_id,
                            'name' => $hw->unit_name,
                        ],
                        'person' => [
                            'n_code' => $hw->n_code,
                            'name' => trim($hw->f_name.' '.$hw->l_name),
                        ],
                    ],
                ];
            })->values()->all();
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Get tickets as GeoJSON FeatureCollection with unit location.
     * Query params: bbox, priority, status
     */
    public function tickets(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $bbox = $this->normalizedBbox($request);
        $extra = array_filter([
            'priority' => $request->filled('priority') ? $request->priority : null,
            'status' => $request->filled('status') ? $request->status : null,
        ], fn ($v) => $v !== null);
        $cacheKey = $this->gisCacheKey('tickets', $accessibleIds, $bbox, $extra);

        $features = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
            $query = Ticket::with('unit:id,name,lat,lng')
                ->whereIn('unit_id', $accessibleIds)
                ->whereHas('unit', function ($q) use ($request) {
                    $q->whereNotNull('lat')->whereNotNull('lng');
                    $this->applyBbox($q, $request);
                });

            if ($request->filled('priority')) {
                $query->where('priority', $request->priority);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $tickets = $query->limit(1000)->get();

            return $tickets->map(function ($ticket) {
                $unit = $ticket->unit;

                return [
                    'type' => 'Feature',
                    'id' => $ticket->id,
                    'geometry' => $unit ? [
                        'type' => 'Point',
                        'coordinates' => [(float) $unit->lng, (float) $unit->lat],
                    ] : null,
                    'properties' => [
                        'id' => $ticket->id,
                        'ticket_code' => $ticket->ticket_code,
                        'title' => $ticket->subject,
                        'priority' => $ticket->priority,
                        'status' => $ticket->status,
                        'unit' => $unit ? [
                            'id' => $unit->id,
                            'name' => $unit->name,
                        ] : null,
                    ],
                ];
            })->values()->all();
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Get summary stats for current viewport.
     * Query params: bbox
     */
    public function stats(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $bbox = $this->normalizedBbox($request);
        $cacheKey = $this->gisCacheKey('stats', $accessibleIds, $bbox);

        $data = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
            // Build bbox conditions once and reuse across all three counts (#402)
            $bboxConditions = [];
            if ($request->filled('bbox')) {
                $bbox = explode(',', $request->bbox);
                if (count($bbox) === 4) {
                    [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
                    $bboxConditions = [
                        ['units.lat', '>=', $minLat],
                        ['units.lat', '<=', $maxLat],
                        ['units.lng', '>=', $minLon],
                        ['units.lng', '<=', $maxLon],
                    ];
                }
            }

            // Units count (in bbox)
            $unitsCount = Unit::whereIn('id', $accessibleIds)
                ->whereNotNull('lat')->whereNotNull('lng')
                ->when($bboxConditions, fn ($q) => $q->where($bboxConditions))
                ->count();

            // Hardware count (in bbox via person.unit) — direct JOIN instead of whereHas (#402)
            $hardwareCount = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
                ->join('units', 'persons.u_id', '=', 'units.id')
                ->whereIn('persons.u_id', $accessibleIds)
                ->whereNotNull('units.lat')->whereNotNull('units.lng')
                ->when($bboxConditions, fn ($q) => $q->where($bboxConditions))
                ->count();

            // Open tickets count (in bbox via unit) — direct JOIN instead of whereHas (#402)
            $ticketsCount = Ticket::join('units', 'tickets.unit_id', '=', 'units.id')
                ->whereIn('tickets.unit_id', $accessibleIds)
                ->where('tickets.status', '!=', 'completed')
                ->whereNotNull('units.lat')->whereNotNull('units.lng')
                ->when($bboxConditions, fn ($q) => $q->where($bboxConditions))
                ->count();

            return [
                'units' => $unitsCount,
                'hardware' => $hardwareCount,
                'open_tickets' => $ticketsCount,
            ];
        });

        return response()->json($data);
    }

    /**
     * Get clustered units for low zoom levels.
     * Query params: zoom, bbox
     */
    public function clusters(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $bbox = $this->normalizedBbox($request);
        $zoom = (int) $request->get('zoom', 10);
        $cacheKey = $this->gisCacheKey('clusters', $accessibleIds, $bbox, ['zoom' => $zoom]);

        $features = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request, $zoom) {
            // Grid size in degrees, adjusted by zoom
            $gridSize = max(0.005, 0.5 / pow(2, $zoom - 2));

            // Build query with raw SQL for grouping (#400)
            // Portable grid bucketing: snap to the nearest grid line via ROUND(l/g)*g —
            // equivalent to PostGIS ST_SnapToGrid but works on sqlite/MySQL/PG.
            $selectRaw = sprintf(
                'COUNT(*) as count,
                ROUND(lng / %f) * %f as lng_key,
                ROUND(lat / %f) * %f as lat_key,
                AVG(lat) as lat,
                AVG(lng) as lng',
                $gridSize, $gridSize, $gridSize, $gridSize
            );

            $query = Unit::whereIn('id', $accessibleIds)
                ->whereNotNull('lat')->whereNotNull('lng')
                ->selectRaw($selectRaw);

            $this->applyBbox($query, $request);

            $clusters = $query
                ->groupBy('lat_key', 'lng_key')
                ->get();

            return $clusters->map(function ($cluster) {
                return [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $cluster->lng, (float) $cluster->lat],
                    ],
                    'properties' => [
                        'count' => (int) $cluster->count,
                    ],
                ];
            })->values()->all();
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Invalidate GIS cache by bumping the version counter via the unified service.
     */
    public static function invalidateCache(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('gis');
    }
}
