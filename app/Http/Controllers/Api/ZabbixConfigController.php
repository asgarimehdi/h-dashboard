<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use App\Models\ZabbixItemPair;
use App\Services\AccessService;
use App\Services\ZabbixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ZabbixConfigController extends Controller
{
    protected ZabbixService $zabbixService;

    public function __construct(ZabbixService $zabbixService)
    {
        $this->zabbixService = $zabbixService;
    }

    // ==================== ZABBIX HOSTS ====================

    public function hostsIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = ZabbixHost::with(['unit:id,name', 'hardware:id,pc_name'])
            ->where(function ($q) use ($accessibleIds) {
                $q->whereNull('unit_id')
                  ->orWhereIn('unit_id', $accessibleIds);
            });

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('host_name', 'LIKE', "%{$s}%")
                  ->orWhere('visible_name', 'LIKE', "%{$s}%")
                  ->orWhere('host_id', 'LIKE', "%{$s}%")
                  ->orWhere('ip', 'LIKE', "%{$s}%");
            });
        }

        $query->orderBy('visible_name');

        $perPage = min((int) $request->get('per_page', 15), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function hostStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => 'nullable|exists:units,id',
            'hardware_id' => 'nullable|exists:hardwares,id',
            'host_id' => 'required|string|unique:zabbix_hosts,host_id',
            'host_name' => 'required|string|max:255',
            'visible_name' => 'required|string|max:255',
            'ip' => 'nullable|ip',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,disabled,maintenance',
            'template_ids' => 'nullable|array',
            'template_ids.*' => 'string',
        ]);

        // Check organizational scope for unit
        if ($validated['unit_id'] ?? false) {
            $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
            if (! in_array($validated['unit_id'], $accessibleIds)) {
                return response()->json(['message' => 'Unit not accessible.'], 403);
            }
        }

        // Check hardware scope
        if ($validated['hardware_id'] ?? false) {
            $hardware = \App\Models\Hardware::find($validated['hardware_id']);
            if ($hardware) {
                $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
                $unitId = $hardware->person?->u_id;
                if ($unitId && ! in_array($unitId, $accessibleIds)) {
                    return response()->json(['message' => 'Hardware not accessible.'], 403);
                }
            }
        }

        $host = ZabbixHost::create($validated);

        return response()->json([
            'success' => true,
            'data' => $host->load(['unit:id,name', 'hardware:id,pc_name']),
        ], 201);
    }

    public function hostShow(Request $request, ZabbixHost $host): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        $host->load(['unit:id,name', 'hardware:id,pc_name', 'items', 'pairs.outItem', 'pairs.inItem', 'pairs.unit:id,name']);

        return response()->json([
            'success' => true,
            'data' => $host,
        ]);
    }

    public function hostUpdate(Request $request, ZabbixHost $host): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        $validated = $request->validate([
            'unit_id' => 'nullable|exists:units,id',
            'hardware_id' => 'nullable|exists:hardwares,id',
            'host_name' => 'sometimes|required|string|max:255',
            'visible_name' => 'sometimes|required|string|max:255',
            'ip' => 'nullable|ip',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,disabled,maintenance',
            'template_ids' => 'nullable|array',
            'template_ids.*' => 'string',
        ]);

        // Check new unit scope
        if (isset($validated['unit_id'])) {
            if ($validated['unit_id'] && ! in_array($validated['unit_id'], $accessibleIds)) {
                return response()->json(['message' => 'Unit not accessible.'], 403);
            }
        }

        $host->update($validated);
        $host->load(['unit:id,name', 'hardware:id,pc_name']);

        return response()->json([
            'success' => true,
            'data' => $host,
        ]);
    }

    public function hostDestroy(Request $request, ZabbixHost $host): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        $host->delete();

        return response()->json(['success' => true, 'message' => 'Zabbix host deleted.']);
    }

    // ==================== HOST SYNC & DISCOVER ====================

    public function hostSync(Request $request, ZabbixHost $host): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        try {
            // Discover items from Zabbix API
            $items = $this->zabbixService->discoverItems($host->host_id);

            $synced = 0;
            foreach ($items as $item) {
                ZabbixItem::updateOrCreate(
                    ['zabbix_host_id' => $host->id, 'item_id' => $item['itemid']],
                    [
                        'item_id' => $item['itemid'],
                        'item_key' => $item['key_'],
                        'name' => $item['name'],
                        'type' => $this->guessType($item['key_']),
                        'unit' => $item['units'] ?? null,
                        'value_type' => $this->mapValueType($item['value_type'] ?? 0),
                        'delay' => $item['delay'] ?? '60s',
                    ]
                );
                $synced++;
            }

            $host->update(['last_sync_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "Discovered and synced {$synced} items from Zabbix.",
                'synced_count' => $synced,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function hostDiscover(Request $request, ZabbixHost $host): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        try {
            $items = $this->zabbixService->discoverItems($host->host_id);

            $formatted = collect($items)->map(function ($item) {
                return [
                    'itemid' => $item['itemid'],
                    'key_' => $item['key_'],
                    'name' => $item['name'],
                    'units' => $item['units'] ?? '',
                    'type' => $this->guessType($item['key_']),
                    'value_type' => $this->mapValueType($item['value_type'] ?? 0),
                    'delay' => $item['delay'] ?? '60s',
                    'already_imported' => ZabbixItem::where('zabbix_host_id', $host->id)
                        ->where('item_id', $item['itemid'])
                        ->exists(),
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Discovery failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==================== ZABBIX ITEMS ====================

    public function itemsIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = ZabbixItem::with(['host.unit:id,name', 'host.hardware:id,pc_name'])
            ->whereHas('host', function ($q) use ($accessibleIds) {
                $q->whereNull('unit_id')->orWhereIn('unit_id', $accessibleIds);
            });

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('is_monitored')) {
            $query->where('is_monitored', $request->boolean('is_monitored'));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'LIKE', "%{$s}%")
                  ->orWhere('item_key', 'LIKE', "%{$s}%")
                  ->orWhere('item_id', 'LIKE', "%{$s}%");
            });
        }

        $query->orderBy('display_order')->orderBy('name');

        $perPage = min((int) $request->get('per_page', 15), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function itemStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'zabbix_host_id' => 'required|exists:zabbix_hosts,id',
            'item_id' => 'required|string|unique:zabbix_items,item_id',
            'item_key' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'type' => 'sometimes|in:traffic_in,traffic_out,cpu,memory,disk,custom',
            'unit' => 'nullable|string|max:50',
            'value_type' => 'sometimes|in:numeric_float,uint,text,log',
            'delay' => 'sometimes|string|max:50',
            'is_monitored' => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        // Check host scope
        $host = ZabbixHost::findOrFail($validated['zabbix_host_id']);
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        $item = ZabbixItem::create($validated);

        return response()->json([
            'success' => true,
            'data' => $item->load('host.unit:id,name'),
        ], 201);
    }

    public function itemShow(Request $request, ZabbixItem $item): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $host = $item->host;
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Item not accessible.'], 403);
        }

        $item->load(['host.unit:id,name']);

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }

    public function itemUpdate(Request $request, ZabbixItem $item): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $host = $item->host;
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Item not accessible.'], 403);
        }

        $validated = $request->validate([
            'item_key' => 'sometimes|required|string|max:255',
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|in:traffic_in,traffic_out,cpu,memory,disk,custom',
            'unit' => 'nullable|string|max:50',
            'value_type' => 'sometimes|in:numeric_float,uint,text,log',
            'delay' => 'sometimes|string|max:50',
            'is_monitored' => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'data' => $item->load('host.unit:id,name'),
        ]);
    }

    public function itemDestroy(Request $request, ZabbixItem $item): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $host = $item->host;
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Item not accessible.'], 403);
        }

        $item->delete();

        return response()->json(['success' => true, 'message' => 'Zabbix item deleted.']);
    }

    // ==================== BULK SYNC ====================

    public function itemsBulkSync(Request $request): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'string|exists:zabbix_items,item_id',
        ]);

        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        // Filter to only accessible items
        $items = ZabbixItem::whereIn('item_id', $request->item_ids)
            ->whereHas('host', function ($q) use ($accessibleIds) {
                $q->whereNull('unit_id')->orWhereIn('unit_id', $accessibleIds);
            })
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No accessible items found.'], 403);
        }

        $itemIds = $items->pluck('item_id')->toArray();
        $values = $this->zabbixService->getLatestValues($itemIds);

        $updated = 0;
        foreach ($items as $item) {
            $value = $values[$item->item_id] ?? null;
            if ($value !== null) {
                $item->update([
                    'last_value' => $value,
                    'last_check_at' => now(),
                ]);
                $updated++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Synced {$updated} items.",
            'synced_count' => $updated,
        ]);
    }

    // ==================== ZABBIX ITEM PAIRS ====================

    public function pairsIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = ZabbixItemPair::with([
            'host.unit:id,name',
            'outItem',
            'inItem',
            'unit:id,name',
        ])
            ->where(function ($q) use ($accessibleIds) {
                $q->whereHas('host', function ($hq) use ($accessibleIds) {
                    $hq->whereNull('unit_id')->orWhereIn('unit_id', $accessibleIds);
                });
            });

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('display_order')->orderBy('name');

        $perPage = min((int) $request->get('per_page', 15), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function pairStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'zabbix_host_id' => 'required|exists:zabbix_hosts,id',
            'name' => 'required|string|max:255',
            'out_item_id' => 'required|exists:zabbix_items,id',
            'in_item_id' => 'required|exists:zabbix_items,id|different:out_item_id',
            'unit_id' => 'nullable|exists:units,id',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        // Check host scope
        $host = ZabbixHost::findOrFail($validated['zabbix_host_id']);
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Host not accessible.'], 403);
        }

        // Verify items belong to same host
        $outItem = ZabbixItem::find($validated['out_item_id']);
        $inItem = ZabbixItem::find($validated['in_item_id']);
        if ($outItem->zabbix_host_id !== $host->id || $inItem->zabbix_host_id !== $host->id) {
            return response()->json(['message' => 'Items must belong to the same host.'], 422);
        }

        // Check unit scope
        if ($validated['unit_id'] ?? false) {
            if (! in_array($validated['unit_id'], $accessibleIds)) {
                return response()->json(['message' => 'Unit not accessible.'], 403);
            }
        }

        $pair = ZabbixItemPair::create($validated);

        return response()->json([
            'success' => true,
            'data' => $pair->load(['outItem', 'inItem', 'unit:id,name']),
        ], 201);
    }

    public function pairShow(Request $request, ZabbixItemPair $pair): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $host = $pair->host;
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Pair not accessible.'], 403);
        }

        $pair->load(['outItem', 'inItem', 'unit:id,name', 'host.unit:id,name']);

        return response()->json([
            'success' => true,
            'data' => $pair,
        ]);
    }

    public function pairUpdate(Request $request, ZabbixItemPair $pair): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $host = $pair->host;
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Pair not accessible.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'out_item_id' => 'sometimes|exists:zabbix_items,id',
            'in_item_id' => 'sometimes|exists:zabbix_items,id|different:out_item_id',
            'unit_id' => 'nullable|exists:units,id',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'display_order' => 'integer|min:0',
        ]);

        if (isset($validated['unit_id']) && $validated['unit_id'] && ! in_array($validated['unit_id'], $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        // Verify items belong to same host if changed
        if (isset($validated['out_item_id']) || isset($validated['in_item_id'])) {
            $outItemId = $validated['out_item_id'] ?? $pair->out_item_id;
            $inItemId = $validated['in_item_id'] ?? $pair->in_item_id;
            $outItem = ZabbixItem::find($outItemId);
            $inItem = ZabbixItem::find($inItemId);
            if ($outItem->zabbix_host_id !== $host->id || $inItem->zabbix_host_id !== $host->id) {
                return response()->json(['message' => 'Items must belong to the same host.'], 422);
            }
        }

        $pair->update($validated);

        return response()->json([
            'success' => true,
            'data' => $pair->load(['outItem', 'inItem', 'unit:id,name']),
        ]);
    }

    public function pairDestroy(Request $request, ZabbixItemPair $pair): JsonResponse
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $host = $pair->host;
        if ($host && $host->unit_id && ! in_array($host->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Pair not accessible.'], 403);
        }

        $pair->delete();

        return response()->json(['success' => true, 'message' => 'Traffic pair deleted.']);
    }

    // ==================== HELPERS ====================

    protected function guessType(string $key): string
    {
        if (str_contains($key, 'net.if.in')) return 'traffic_in';
        if (str_contains($key, 'net.if.out')) return 'traffic_out';
        if (str_contains($key, 'cpu')) return 'cpu';
        if (str_contains($key, 'mem')) return 'memory';
        if (str_contains($key, 'disk') || str_contains($key, 'vfs.fs')) return 'disk';
        return 'custom';
    }

    protected function mapValueType(int $type): string
    {
        return match ($type) {
            0 => 'numeric_float',
            1 => 'text',
            2 => 'log',
            3 => 'uint',
            default => 'numeric_float',
        };
    }
}