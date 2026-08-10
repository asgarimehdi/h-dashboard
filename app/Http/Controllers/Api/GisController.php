<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GisController extends Controller
{
    /**
     * Apply bbox spatial filter using lat/lng columns (works without geom column).
     */
    protected function applyBbox($query, Request $request)
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
     * Build a normalized bbox string for cache keys (2 decimal places).
     */
    protected function normalizedBbox(Request $request): string
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
            round($minLon, 2),
            round($minLat, 2),
            round($maxLon, 2),
            round($maxLat, 2),
        ]);
    }

    /**
     * Build a cache key for GIS endpoints.
     */
    protected function gisCacheKey(string $endpoint, array $accessibleIds, string $bbox, array $extra = []): string
    {
        $version = Cache::get('gis_version', 0);
        $scopeHash = md5(implode(',', $accessibleIds));
        $extraHash = empty($extra) ? 'none' : md5(serialize($extra));

        return "gis_{$endpoint}:v{$version}:{$scopeHash}:{$bbox}:{$extraHash}";
    }

    /**
     * Get units as GeoJSON FeatureCollection within spatial bounds.
     * Query params: bbox (minLon,minLat,maxLon,maxLat)
     */
    public function units(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
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
    public function hardware(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $bbox = $this->normalizedBbox($request);
        $extra = array_filter([
            'type' => $request->filled('type') ? $request->type : null,
            'shutdown' => $request->filled('shutdown') ? $request->boolean('shutdown') : null,
            'mark' => $request->filled('mark') ? $request->boolean('mark') : null,
        ], fn ($v) => $v !== null); // only remove null, keep false/0
        $cacheKey = $this->gisCacheKey('hardware', $accessibleIds, $bbox, $extra);

        $features = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
            $query = Hardware::with('person.unit:id,name,lat,lng')
                ->whereHas('person', function ($q) use ($accessibleIds) {
                    $q->whereIn('u_id', $accessibleIds);
                })
                ->whereHas('person.unit', function ($q) use ($request) {
                    $q->whereNotNull('lat')->whereNotNull('lng');
                    $this->applyBbox($q, $request);
                });

            if ($request->filled('type')) {
                $query->where('type', 'LIKE', "%{$request->type}%");
            }
            if ($request->filled('shutdown')) {
                $query->where('shutdown', $request->boolean('shutdown'));
            }
            if ($request->filled('mark')) {
                $query->where('mark', $request->boolean('mark'));
            }

            $hardware = $query->limit(1000)->get();

            return $hardware->map(function ($hw) {
                $unit = $hw->person?->unit;

                return [
                    'type' => 'Feature',
                    'id' => $hw->id,
                    'geometry' => $unit ? [
                        'type' => 'Point',
                        'coordinates' => [(float) $unit->lng, (float) $unit->lat],
                    ] : null,
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
                        'unit' => $unit ? [
                            'id' => $unit->id,
                            'name' => $unit->name,
                        ] : null,
                        'person' => $hw->person ? [
                            'n_code' => $hw->person->n_code,
                            'name' => trim($hw->person->f_name . ' ' . $hw->person->l_name),
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
     * Get tickets as GeoJSON FeatureCollection with unit location.
     * Query params: bbox, priority, status
     */
    public function tickets(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
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
                        'title' => $ticket->title,
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
    public function stats(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $bbox = $this->normalizedBbox($request);
        $cacheKey = $this->gisCacheKey('stats', $accessibleIds, $bbox);

        $data = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($accessibleIds, $request) {
            // Units count (in bbox)
            $unitsQuery = Unit::whereIn('id', $accessibleIds)
                ->whereNotNull('lat')->whereNotNull('lng');
            $this->applyBbox($unitsQuery, $request);
            $unitsCount = $unitsQuery->count();

            // Hardware count (in bbox via person.unit)
            $hardwareQuery = Hardware::whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })->whereHas('person.unit', function ($q) use ($request) {
                $q->whereNotNull('lat')->whereNotNull('lng');
                $this->applyBbox($q, $request);
            });
            $hardwareCount = $hardwareQuery->count();

            // Open tickets count (in bbox via unit)
            $ticketsQuery = Ticket::whereIn('unit_id', $accessibleIds)
                ->where('status', '!=', 'completed')
                ->whereHas('unit', function ($q) use ($request) {
                    $q->whereNotNull('lat')->whereNotNull('lng');
                    $this->applyBbox($q, $request);
                });
            $openTicketsCount = $ticketsQuery->count();

            return [
                'units' => $unitsCount,
                'hardware' => $hardwareCount,
                'open_tickets' => $openTicketsCount,
            ];
        });

        return response()->json($data);
    }

    /**
     * Get clustered units for low zoom levels.
     * Query params: zoom, bbox
     */
    public function clusters(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $bbox = $this->normalizedBbox($request);
        $zoom = (int) $request->get('zoom', 10);
        $cacheKey = $this->gisCacheKey('clusters', $accessibleIds, $bbox, ['zoom' => $zoom]);

        $features = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($accessibleIds, $request, $zoom) {
            // Grid size in degrees, adjusted by zoom
            $gridSize = max(0.005, 0.5 / pow(2, $zoom - 2));

            // Build query with raw SQL for grouping (#400)
            // ST_SnapToGrid snaps lat/lng to the grid in one pass — cheaper than ROUND(x/g)*g,
            // and lets PostGIS use spatial indexes where available.
            $selectRaw = sprintf(
                "COUNT(*) as count,
                ST_X(ST_SnapToGrid(ST_MakePoint(lng, lat), %f)) as lng_key,
                ST_Y(ST_SnapToGrid(ST_MakePoint(lng, lat), %f)) as lat_key,
                AVG(lat) as lat,
                AVG(lng) as lng",
                $gridSize, $gridSize
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
     * Invalidate GIS cache by bumping the version counter.
     */
    public static function invalidateCache(): void
    {
        Cache::increment('gis_version');
    }
}
