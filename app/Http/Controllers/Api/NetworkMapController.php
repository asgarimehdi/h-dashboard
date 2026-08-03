<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\NetworkLink;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NetworkMapController extends Controller
{
    public function switches(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Get bounding box from request
        $bounds = $request->validate([
            'min_lat' => 'nullable|numeric|between:-90,90',
            'max_lat' => 'nullable|numeric|between:-90,90',
            'min_lng' => 'nullable|numeric|between:-180,180',
            'max_lng' => 'nullable|numeric|between:-180,180',
        ]);

        $query = Hardware::with('person.unit')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })
            ->whereNotNull('switch')
            ->where('switch', '!=', '');

        // Apply bounding box filter if provided
        if (!empty($bounds['min_lat'])) {
            $query->whereHas('person.unit', function ($q) use ($bounds) {
                $q->whereBetween('lat', [$bounds['min_lat'], $bounds['max_lat']])
                    ->whereBetween('lng', [$bounds['min_lng'], $bounds['max_lng']]);
            });
        }

        $hardwares = $query->get();

        // Group by switch name and aggregate
        $switches = $hardwares->groupBy('switch')->map(function ($group, $switchName) {
            $first = $group->first();
            $unit = $first->person->unit ?? null;

            $vlans = $group->pluck('vlan')->filter()->unique()->values();
            $types = $group->pluck('type')->filter()->unique()->values();
            $netTypes = $group->pluck('net_type')->filter()->unique()->values();

            // Count devices
            $deviceCount = $group->count();
            $portCount = $group->whereNotNull('port')->where('port', '!=', '')->count();

            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        (float) $unit->lng,
                        (float) $unit->lat,
                    ],
                ],
                'properties' => [
                    'switch' => $switchName,
                    'unit_id' => $unit->id ?? null,
                    'unit_name' => $unit->name ?? null,
                    'device_count' => $deviceCount,
                    'port_count' => $portCount,
                    'vlans' => $vlans->values(),
                    'types' => $types->values(),
                    'net_types' => $netTypes->values(),
                ],
            ];
        })->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $switches,
        ]);
    }

    public function links(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = NetworkLink::with(['sourceUnit', 'targetUnit'])
            ->where(function ($q) use ($accessibleIds) {
                $q->whereIn('source_unit_id', $accessibleIds)
                    ->orWhereIn('target_unit_id', $accessibleIds);
            });

        // Apply bounding box filter
        $bounds = $request->validate([
            'min_lat' => 'nullable|numeric|between:-90,90',
            'max_lat' => 'nullable|numeric|between:-90,90',
            'min_lng' => 'nullable|numeric|between:-180,180',
            'max_lng' => 'nullable|numeric|between:-180,180',
        ]);

        if (!empty($bounds['min_lat'])) {
            $query->where(function ($q) use ($bounds) {
                $q->whereHas('sourceUnit', function ($uq) use ($bounds) {
                    $uq->whereBetween('lat', [$bounds['min_lat'], $bounds['max_lat']])
                        ->whereBetween('lng', [$bounds['min_lng'], $bounds['max_lng']]);
                })->orWhereHas('targetUnit', function ($uq) use ($bounds) {
                    $uq->whereBetween('lat', [$bounds['min_lat'], $bounds['max_lat']])
                        ->whereBetween('lng', [$bounds['min_lng'], $bounds['max_lng']]);
                });
            });
        }

        $links = $query->get()->map(function ($link) {
            $source = $link->sourceUnit;
            $target = $link->targetUnit;

            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [(float) $source->lng, (float) $source->lat],
                        [(float) $target->lng, (float) $target->lat],
                    ],
                ],
                'properties' => [
                    'source_switch' => $link->source_switch,
                    'target_switch' => $link->target_switch,
                    'link_type' => $link->link_type,
                    'vlans' => $link->vlans ?? [],
                    'distance_km' => $link->distance_km,
                    'latency_ms' => $link->latency_ms,
                    'bandwidth_mbps' => $link->bandwidth_mbps,
                    'is_redundant' => $link->is_redundant,
                    'source_unit' => $source ? ['id' => $source->id, 'name' => $source->name] : null,
                    'target_unit' => $target ? ['id' => $target->id, 'name' => $target->name] : null,
                ],
            ];
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $links,
        ]);
    }

    public function vlans(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $hardwares = Hardware::with('person.unit')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })
            ->whereNotNull('vlan')
            ->where('vlan', '!=', '')
            ->get();

        $vlans = $hardwares->groupBy('vlan')->map(function ($group, $vlan) {
            $first = $group->first();
            return [
                'vlan' => $vlan,
                'switch_count' => $group->pluck('switch')->filter()->unique()->count(),
                'link_count' => $group->pluck('switch')->filter()->unique()->count(), // approximate
                'unit_count' => $group->pluck('person.u_id')->unique()->count(),
                'device_count' => $group->count(),
                'color' => $this->vlanColor($vlan),
            ];
        })->values();

        return response()->json($vlans);
    }

    public function spof(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Get switches and count how many units each serves
        $switches = Hardware::with('person.unit')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })
            ->whereNotNull('switch')
            ->where('switch', '!=', '')
            ->get()
            ->groupBy('switch')
            ->map(function ($group) {
                $first = $group->first();
                $unit = $first->person->unit ?? null;
                $deviceCount = $group->count();
                $unitCount = $group->pluck('person.u_id')->unique()->count();

                // SPOF score: higher if serves many units and is not redundant
                // For now, simple heuristic: more units = higher risk
                $riskScore = $unitCount * 10 + $deviceCount;

                return [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [
                            (float) ($unit->lng ?? 0),
                            (float) ($unit->lat ?? 0),
                        ],
                    ],
                    'properties' => [
                        'switch' => $group->first()->switch,
                        'unit_id' => $unit->id ?? null,
                        'unit_name' => $unit->name ?? null,
                        'unit_count' => $unitCount,
                        'device_count' => $deviceCount,
                        'risk_score' => $riskScore,
                        'risk_level' => $riskScore > 50 ? 'critical' : ($riskScore > 20 ? 'high' : 'medium'),
                    ],
                ];
            })
            ->sortByDesc('properties.risk_score')
            ->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $switches,
        ]);
    }

    public function trace(Request $request): JsonResponse
    {
        $request->validate([
            'source' => 'required|string', // switch name or hardware id
            'target' => 'required|string',
        ]);

        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // This is a simplified path finding - in production would use actual network graph
        // For now, return a simple line between the two points
        $sourceHw = Hardware::with('person.unit')
            ->where('switch', $request->source)
            ->orWhere('id', $request->source)
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })
            ->first();

        $targetHw = Hardware::with('person.unit')
            ->where('switch', $request->target)
            ->orWhere('id', $request->target)
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })
            ->first();

        if (!$sourceHw || !$targetHw) {
            return response()->json(['error' => 'Source or target not found'], 404);
        }

        $sourceUnit = $sourceHw->person->unit;
        $targetUnit = $targetHw->person->unit;

        $path = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [
                            [(float) $sourceUnit->lng, (float) $sourceUnit->lat],
                            [(float) $targetUnit->lng, (float) $targetUnit->lat],
                        ],
                    ],
                    'properties' => [
                        'source' => $sourceHw->switch ?? $sourceHw->id,
                        'target' => $targetHw->switch ?? $targetHw->id,
                        'distance_km' => $this->calculateDistance(
                            $sourceUnit->lat, $sourceUnit->lng,
                            $targetUnit->lat, $targetUnit->lng
                        ),
                    ],
                ],
            ],
        ];

        return response()->json($path);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $stats = Cache::remember('network-map-stats:' . md5(json_encode($accessibleIds)), 300, function () use ($accessibleIds) {
            $hardwares = Hardware::whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })->get();

            $switches = $hardwares->whereNotNull('switch')->where('switch', '!=', '')->pluck('switch')->unique();
            $links = NetworkLink::where(function ($q) use ($accessibleIds) {
                $q->whereIn('source_unit_id', $accessibleIds)
                    ->orWhereIn('target_unit_id', $accessibleIds);
            })->get();

            $vlans = $hardwares->whereNotNull('vlan')->where('vlan', '!=', '')->pluck('vlan')->unique();

            // SPOF count
            $spofCount = 0;
            $switchGroups = $hardwares->whereNotNull('switch')->where('switch', '!=', '')->groupBy('switch');
            foreach ($switchGroups as $group) {
                $unitCount = $group->pluck('person.u_id')->unique()->count();
                if ($unitCount > 5) {
                    $spofCount++;
                }
            }

            return [
                'total_switches' => $switches->count(),
                'total_links' => $links->count(),
                'total_vlans' => $vlans->count(),
                'total_devices' => $hardwares->count(),
                'spof_count' => $spofCount,
                'avg_latency_ms' => $links->avg('latency_ms') ?? 0,
            ];
        });

        return response()->json($stats);
    }

    public function devices(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $bounds = $request->validate([
            'min_lat' => 'nullable|numeric|between:-90,90',
            'max_lat' => 'nullable|numeric|between:-90,90',
            'min_lng' => 'nullable|numeric|between:-180,180',
            'max_lng' => 'nullable|numeric|between:-180,180',
        ]);

        $query = Hardware::with('person.unit')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            });

        if (!empty($bounds['min_lat'])) {
            $query->whereHas('person.unit', function ($q) use ($bounds) {
                $q->whereBetween('lat', [$bounds['min_lat'], $bounds['max_lat']])
                    ->whereBetween('lng', [$bounds['min_lng'], $bounds['max_lng']]);
            });
        }

        $devices = $query->get()->map(function ($hw) {
            $unit = $hw->person->unit ?? null;
            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        (float) ($unit->lng ?? 0),
                        (float) ($unit->lat ?? 0),
                    ],
                ],
                'properties' => [
                    'id' => $hw->id,
                    'n_code' => $hw->n_code,
                    'pc_name' => $hw->pc_name,
                    'type' => $hw->type,
                    'os' => $hw->os,
                    'ip_valid' => $hw->ip_valid,
                    'ip_local' => $hw->ip_local,
                    'mac' => $hw->mac,
                    'net_type' => $hw->net_type,
                    'switch' => $hw->switch,
                    'port' => $hw->port,
                    'vlan' => $hw->vlan,
                    'unit_name' => $unit->name ?? null,
                    'unit_id' => $unit->id ?? null,
                ],
            ];
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $devices,
        ]);
    }

    private function vlanColor(int $vlan): string
    {
        $colors = [
            1 => '#e74c3c', 10 => '#3498db', 20 => '#2ecc71', 30 => '#f39c12',
            40 => '#9b59b6', 50 => '#1abc9c', 100 => '#e67e22', 200 => '#34495e',
        ];
        return $colors[$vlan] ?? '#' . substr(md5((string)$vlan), 0, 6);
    }

    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return round($earthRadius * $c, 2);
    }
}