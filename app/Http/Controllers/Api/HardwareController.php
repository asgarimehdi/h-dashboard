<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\Person;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class HardwareController extends Controller
{
    use PersianNormalizer;

    /**
     * Check if the given hardware record is within the user's accessible organizational scope.
     */
    private function assertAccessible(Request $request, Hardware $hardware): void
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $unitId = $hardware->relationLoaded('person')
            ? $hardware->person?->u_id
            : $hardware->person()->value('u_id');

        if ($unitId && ! in_array($unitId, $accessibleIds)) {
            abort(403, 'Hardware record not accessible.');
        }
    }

    /**
     * Transform hardware model to array (inline resource logic).
     */
    private function transformHardware(Hardware $hardware): array
    {
        return [
            'id' => $hardware->id,
            'n_code' => $hardware->n_code,
            'pc_name' => $hardware->pc_name,
            'type' => $hardware->type,
            'os' => $hardware->os,
            'ip_valid' => $hardware->ip_valid,
            'ip_local' => $hardware->ip_local,
            'mac' => $hardware->mac,
            'net_type' => $hardware->net_type,
            'switch' => $hardware->switch,
            'port' => $hardware->port,
            'shutdown' => (bool) $hardware->shutdown,
            'vlan' => $hardware->vlan,
            'motherboard' => $hardware->motherboard,
            'cpu' => $hardware->cpu,
            'ram' => $hardware->ram,
            'hdd' => $hardware->hdd,
            'comments' => $hardware->comments,
            'mark' => (bool) $hardware->mark,
            'clean_at' => $hardware->clean_at?->format('Y-m-d'),
            'created_at' => $hardware->created_at?->toIso8601String(),
            'updated_at' => $hardware->updated_at?->toIso8601String(),
            'person' => $hardware->relationLoaded('person') && $hardware->person ? [
                'n_code' => $hardware->person->n_code,
                'name' => trim($hardware->person->f_name . ' ' . $hardware->person->l_name),
                'unit' => $hardware->person->unit?->name,
            ] : null,
        ];
    }

    public function index(Request $request): array
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Hardware::with('person.unit')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            });

        // Filters
        if ($request->filled('search')) {
            $s = self::normalizeForSearch($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('pc_name', 'LIKE', "%{$s}%")
                  ->orWhere('n_code', 'LIKE', "%{$s}%")
                  ->orWhere('ip_valid', 'LIKE', "%{$s}%")
                  ->orWhere('ip_local', 'LIKE', "%{$s}%")
                  ->orWhere('mac', 'LIKE', "%{$s}%")
                  ->orWhere('comments', 'LIKE', "%{$s}%")
                  ->orWhereHas('person', function ($pq) use ($s) {
                      $pq->where('f_name', 'LIKE', "%{$s}%")
                        ->orWhere('l_name', 'LIKE', "%{$s}%");
                  });
            });
        }

        if ($request->filled('type')) {
            $type = $request->type;
            // Map common aliases to actual database values
            $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
            $type = $typeAliases[$type] ?? $type;
            $query->where('type', 'LIKE', "%{$type}%");
        }
        if ($request->filled('os')) {
            $query->where('os', 'LIKE', "%{$request->os}%");
        }
        if ($request->filled('cpu')) {
            $query->where('cpu', 'LIKE', "%{$request->cpu}%");
        }
        if ($request->filled('ram')) {
            $query->where('ram', 'LIKE', "%{$request->ram}%");
        }
        if ($request->filled('hdd')) {
            $query->where('hdd', 'LIKE', "%{$request->hdd}%");
        }
        if ($request->filled('shutdown')) {
            $query->where('shutdown', $request->shutdown === 'true' || $request->shutdown === '1');
        }
        if ($request->filled('net_type')) {
            $query->where('net_type', 'LIKE', "%{$request->net_type}%");
        }
        if ($request->filled('mark')) {
            $query->where('mark', $request->mark === 'true' || $request->mark === '1');
        }
        if ($request->filled('person')) {
            $normalized = self::normalizeForSearch($request->person);
            $query->whereHas('person', function ($q) use ($normalized) {
                $q->where('f_name', 'LIKE', "%{$normalized}%")
                  ->orWhere('l_name', 'LIKE', "%{$normalized}%")
                  ->orWhere('n_code', 'LIKE', "%{$normalized}%");
            });
        }
        if ($request->filled('unit')) {
            $normalized = self::normalizeForSearch($request->unit);
            $query->whereHas('person.unit', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }
        if ($request->filled('semat')) {
            $normalized = self::normalizeForSearch($request->semat);
            $query->whereHas('person.semat', function ($q) use ($normalized) {
                $q->where('name', 'LIKE', "%{$normalized}%");
            });
        }

        $allowedSortColumns = ['id', 'n_code', 'pc_name', 'type', 'os', 'created_at', 'shutdown', 'mark', 'ip_valid', 'ip_local', 'mac', 'cpu', 'ram', 'hdd'];
        $sortBy = $request->get('sort_by', 'id');
        if (! in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'id';
        }

        $sortDir = strtolower($request->get('sort_dir', 'desc'));
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) $request->get('per_page', 10), 100);
        
        $paginator = $query->paginate($perPage);
        $items = $paginator->getCollection()->map(fn($hw) => $this->transformHardware($hw))->all();
        
        return [
            'data' => $items,
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'n_code' => 'required|string|exists:persons,n_code',
            'pc_name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'os' => 'nullable|string|max:100',
            'ip_valid' => 'nullable|string|max:45',
            'ip_local' => 'nullable|string|max:45',
            'mac' => 'nullable|string|max:17',
            'net_type' => 'nullable|string|max:50',
            'switch' => 'nullable|string|max:100',
            'port' => 'nullable|string|max:50',
            'vlan' => 'nullable|string|max:50',
            'motherboard' => 'nullable|string|max:100',
            'cpu' => 'nullable|string|max:100',
            'ram' => 'nullable|string|max:50',
            'hdd' => 'nullable|string|max:100',
            'comments' => 'nullable|string',
            'mark' => 'boolean',
            'clean_at' => 'nullable|date',
        ]);

        // Verify the person's unit is within the user's accessible scope
        $person = Person::where('n_code', $validated['n_code'])->firstOrFail();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        if (! in_array($person->u_id, $accessibleIds)) {
            return response()->json(['message' => 'Person not accessible.'], 403);
        }

        $hardware = Hardware::create($validated);
        $hardware->load('person');

        return response()->json([
            'success' => true,
            'data' => $this->transformHardware($hardware),
        ], 201);
    }

    public function show(Request $request, Hardware $hardware): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        $hardware->load('person');

        return response()->json([
            'success' => true,
            'data' => $this->transformHardware($hardware),
        ]);
    }

    public function update(Request $request, Hardware $hardware): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        $validated = $request->validate([
            'n_code' => 'sometimes|required|string|exists:persons,n_code',
            'pc_name' => 'sometimes|required|string|max:255',
            'type' => 'nullable|string|max:50',
            'os' => 'nullable|string|max:100',
            'ip_valid' => 'nullable|string|max:45',
            'ip_local' => 'nullable|string|max:45',
            'mac' => 'nullable|string|max:17',
            'net_type' => 'nullable|string|max:50',
            'switch' => 'nullable|string|max:100',
            'port' => 'nullable|string|max:50',
            'vlan' => 'nullable|string|max:50',
            'motherboard' => 'nullable|string|max:100',
            'cpu' => 'nullable|string|max:100',
            'ram' => 'nullable|string|max:50',
            'hdd' => 'nullable|string|max:100',
            'comments' => 'nullable|string',
            'mark' => 'boolean',
            'clean_at' => 'nullable|date',
            'shutdown' => 'boolean',
        ]);

        $hardware->update($validated);
        $hardware->load('person');

        return response()->json([
            'success' => true,
            'data' => $this->transformHardware($hardware),
        ]);
    }

    public function destroy(Request $request, Hardware $hardware): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        $hardware->delete();
        return response()->json(['success' => true, 'message' => 'حذف شد']);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Issue #217: cache stats to avoid 3 heavy queries per request.
        // Key is scoped by (version, accessible units): the version counter is
        // bumped on every hardware write (Hardware::flushStatsCache()), which
        // invalidates all previously cached scopes without flushing the whole
        // cache. Stale entries expire naturally via the 10-minute TTL.
        $version = Cache::get('hardware_stats_version', 0);
        $cacheKey = "hardware_stats:v{$version}:" . md5(json_encode($accessibleIds));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds) {
            $baseQuery = Hardware::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds));

            return [
                'total' => $baseQuery->count(),
                'by_type' => (clone $baseQuery)->selectRaw('type, count(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->toArray(),
                'shutdown' => (clone $baseQuery)->where('shutdown', true)->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function bulkMark(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $count = Hardware::whereIn('id', $request->ids)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->count();
        if ($count !== count($request->ids)) {
            return response()->json(['message' => 'Some hardware records are not accessible.'], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:hardwares,id',
            'mark' => 'required|boolean',
        ]);

        $hardwares = Hardware::whereIn('id', $request->ids)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->get();

        $count = Hardware::whereIn('id', $request->ids)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->update(['mark' => $request->mark]);

        // Log audit for each hardware (unified audit trail, Issue #246)
        $observer = app(\App\Observers\HardwareAuditObserver::class);
        foreach ($hardwares as $hardware) {
            $observer->recordBulkAudit($hardware, 'bulk_mark', [
                ['field' => 'mark', 'old' => !$request->mark, 'new' => $request->mark],
            ]);
        }

        return response()->json(['success' => true, 'message' => "$count device(s) updated", 'count' => $count]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $count = Hardware::whereIn('id', $request->ids)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->count();
        if ($count !== count($request->ids)) {
            return response()->json(['message' => 'Some hardware records are not accessible.'], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:hardwares,id',
        ]);

        $hardwares = Hardware::whereIn('id', $request->ids)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->get();

        // Log audit for each hardware before deletion (unified audit trail, Issue #246)
        $observer = app(\App\Observers\HardwareAuditObserver::class);
        foreach ($hardwares as $hardware) {
            $observer->recordBulkAudit($hardware, 'bulk_delete', $hardware->getAttributes());
        }

        $count = Hardware::whereIn('id', $request->ids)
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->delete();

        return response()->json(['success' => true, 'message' => "$count device(s) deleted", 'count' => $count]);
    }
}