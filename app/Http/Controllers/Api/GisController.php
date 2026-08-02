<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GisController extends Controller
{
    /**
     * Get units as GeoJSON FeatureCollection within spatial bounds.
     * Query params: bbox (minLon,minLat,maxLon,maxLat), radius (lat,lng,meters), polygon (WKT)
     */
    public function units(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Unit::whereIn('id', $accessibleIds)
            ->whereNotNull('geom')
            ->select('id', 'name', 'unit_type_id', 'parent_id', 'region_id', 'lat', 'lng', 'geom');

        // Spatial filtering
        if ($request->filled('bbox')) {
            $bbox = explode(',', $request->bbox);
            if (count($bbox) === 4) {
                [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
                $query->whereRaw(
                    "geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)",
                    [$minLon, $minLat, $maxLon, $maxLat]
                );
            }
        } elseif ($request->filled('radius')) {
            $radius = explode(',', $request->radius);
            if (count($radius) === 3) {
                [$lat, $lng, $meters] = array_map('floatval', $radius);
                $query->whereRaw(
                    "ST_DWithin(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                    [$lng, $lat, $meters]
                );
            }
        } elseif ($request->filled('polygon')) {
            $wkt = $request->polygon;
            $query->whereRaw("ST_Within(geom, ST_GeomFromText(?, 4326))", [$wkt]);
        }

        // Limit results for performance
        $units = $query->limit(1000)->get();

        $features = $units->map(function ($unit) {
            return [
                'type' => 'Feature',
                'id' => $unit->id,
                'geometry' => json_decode($unit->geom),
                'properties' => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'unit_type_id' => $unit->unit_type_id,
                    'parent_id' => $unit->parent_id,
                    'region_id' => $unit->region_id,
                    'lat' => $unit->lat,
                    'lng' => $unit->lng,
                ],
            ];
        })->values()->all();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Get hardware as GeoJSON FeatureCollection with parent unit geometry.
     * Query params: bbox, radius, polygon, type, shutdown, mark
     */
    public function hardware(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Hardware::with('person.unit:id,name,lat,lng,geom')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            });

        // Filters
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

        $features = $hardware->map(function ($hw) {
            $unit = $hw->person?->unit;
            $geom = $unit?->geom;

            return [
                'type' => 'Feature',
                'id' => $hw->id,
                'geometry' => $geom ? json_decode($geom) : null,
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

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Get tickets as GeoJSON FeatureCollection with unit geometry.
     * Query params: bbox, radius, polygon, priority, status
     */
    public function tickets(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Ticket::with('unit:id,name,lat,lng,geom')
            ->whereIn('unit_id', $accessibleIds);

        // Filters
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tickets = $query->limit(1000)->get();

        $features = $tickets->map(function ($ticket) {
            $unit = $ticket->unit;
            $geom = $unit?->geom;

            return [
                'type' => 'Feature',
                'id' => $ticket->id,
                'geometry' => $geom ? json_decode($geom) : null,
                'properties' => [
                    'id' => $ticket->id,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'ticket_code' => $ticket->ticket_code,
                    'unit' => $unit ? [
                        'id' => $unit->id,
                        'name' => $unit->name,
                    ] : null,
                ],
            ];
        })->values()->all();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * Get summary stats for current viewport.
     * Query params: bbox, radius, polygon
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Build spatial filter
        $spatialFilter = function ($query) use ($request) {
            if ($request->filled('bbox')) {
                $bbox = explode(',', $request->bbox);
                if (count($bbox) === 4) {
                    [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
                    $query->whereRaw(
                        "geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)",
                        [$minLon, $minLat, $maxLon, $maxLat]
                    );
                }
            } elseif ($request->filled('radius')) {
                $radius = explode(',', $request->radius);
                if (count($radius) === 3) {
                    [$lat, $lng, $meters] = array_map('floatval', $radius);
                    $query->whereRaw(
                        "ST_DWithin(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                        [$lng, $lat, $meters]
                    );
                }
            } elseif ($request->filled('polygon')) {
                $wkt = $request->polygon;
                $query->whereRaw("ST_Within(geom, ST_GeomFromText(?, 4326))", [$wkt]);
            }
        };

        // Units count
        $unitsQuery = Unit::whereIn('id', $accessibleIds)->whereNotNull('geom');
        $spatialFilter($unitsQuery);
        $unitsCount = $unitsQuery->count();

        // Hardware count
        $hardwareQuery = Hardware::whereHas('person', function ($q) use ($accessibleIds) {
            $q->whereIn('u_id', $accessibleIds);
        })->whereHas('person.unit', function ($q) use ($spatialFilter) {
            $q->whereNotNull('geom');
            $spatialFilter($q);
        });
        $hardwareCount = $hardwareQuery->count();

        // Open tickets count
        $ticketsQuery = Ticket::whereIn('unit_id', $accessibleIds)
            ->whereHas('unit', function ($q) use ($spatialFilter) {
                $q->whereNotNull('geom');
                $spatialFilter($q);
            })->where('status', '!=', 'completed');
        $openTicketsCount = $ticketsQuery->count();

        return response()->json([
            'units' => $unitsCount,
            'hardware' => $hardwareCount,
            'open_tickets' => $openTicketsCount,
        ]);
    }

    /**
     * Get clustered units for low zoom levels.
     * Query params: zoom, bbox
     */
    public function clusters(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $zoom = $request->get('zoom', 10);
        $gridSize = max(0.001, 0.1 / pow(2, $zoom - 5)); // Adjust grid based on zoom

        $bbox = $request->get('bbox');

        // Build query using Eloquent to avoid raw SQL parameter issues
        $query = Unit::whereIn('id', $accessibleIds)
            ->whereNotNull('geom');

        if ($bbox) {
            $bboxArr = explode(',', $bbox);
            if (count($bboxArr) === 4) {
                [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bboxArr);
                $query->whereRaw(
                    "geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)",
                    [$minLon, $minLat, $maxLon, $maxLat]
                );
            }
        }

        // Use ST_SnapToGrid for clustering
        $clusters = $query->selectRaw(
            "COUNT(*) as count,
            ST_Centroid(ST_Collect(geom)) as center,
            ST_X(ST_Centroid(ST_Collect(geom))) as lng,
            ST_Y(ST_Centroid(ST_Collect(geom))) as lat"
        )
        ->groupByRaw("ST_SnapToGrid(geom, ?)", [$gridSize])
        ->get();

        $features = $clusters->map(function ($cluster) {
            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$cluster->lng, $cluster->lat],
                ],
                'properties' => [
                    'count' => (int) $cluster->count,
                ],
            ];
        })->values()->all();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]);
    }
}
