<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        if (! $unitId || ! in_array($unitId, $accessibleIds)) {
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
                'name' => trim($hardware->person->f_name.' '.$hardware->person->l_name),
                'unit' => $hardware->person->unit?->name,
            ] : null,
        ];
    }

    public function index(Request $request): array
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
            ->whereIn('persons.u_id', $accessibleIds)
            ->select('hardwares.*')
            ->distinct();

        // Filters
        if ($request->filled('search')) {
            $s = self::normalizeForSearch($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('pc_name', 'LIKE', "%{$s}%")
                    ->orWhere('hardwares.n_code', 'LIKE', "%{$s}%")
                    ->orWhere('ip_valid', 'LIKE', "%{$s}%")
                    ->orWhere('ip_local', 'LIKE', "%{$s}%")
                    ->orWhere('mac', 'LIKE', "%{$s}%")
                    ->orWhere('comments', 'LIKE', "%{$s}%")
                    ->orWhere('persons.f_name', 'LIKE', "%{$s}%")
                    ->orWhere('persons.l_name', 'LIKE', "%{$s}%");
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
            $query->where(function ($q) use ($normalized) {
                $q->where('persons.f_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('persons.l_name', 'LIKE', "%{$normalized}%")
                    ->orWhere('persons.n_code', 'LIKE', "%{$normalized}%");
            });
        }
        if ($request->filled('unit')) {
            $normalized = self::normalizeForSearch($request->unit);
            $query->whereExists(function ($q) use ($normalized) {
                $q->selectRaw('1')
                    ->from('units')
                    ->whereColumn('units.id', 'persons.u_id')
                    ->where('units.name', 'LIKE', "%{$normalized}%");
            });
        }
        if ($request->filled('semat')) {
            $normalized = self::normalizeForSearch($request->semat);
            $query->whereExists(function ($q) use ($normalized) {
                $q->selectRaw('1')
                    ->from('semats')
                    ->whereColumn('semats.id', 'persons.s_id')
                    ->where('semats.name', 'LIKE', "%{$normalized}%");
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
        $paginator->getCollection()->load('person.unit');
        $items = $paginator->getCollection()->map(fn ($hw) => $this->transformHardware($hw))->all();

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
        $hardware->load('person.unit');
        GisController::invalidateCache();

        return response()->json([
            'success' => true,
            'data' => $this->transformHardware($hardware),
        ], 201);
    }

    public function show(Request $request, Hardware $hardware): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        $hardware->load('person.unit');

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

        // Verify the new person's unit is within the user's accessible scope (if n_code is being changed)
        if (isset($validated['n_code'])) {
            $newPerson = Person::where('n_code', $validated['n_code'])->firstOrFail();
            $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
            if (! in_array($newPerson->u_id, $accessibleIds)) {
                return response()->json(['message' => 'Cannot assign hardware to a person in an inaccessible unit.'], 403);
            }
        }

        $hardware->update($validated);
        $hardware->load('person.unit');
        GisController::invalidateCache();

        return response()->json([
            'success' => true,
            'data' => $this->transformHardware($hardware),
        ]);
    }

    public function destroy(Request $request, Hardware $hardware): JsonResponse
    {
        $this->assertAccessible($request, $hardware);

        $hardware->delete();
        GisController::invalidateCache();

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
        $cacheKey = "hardware_stats:v{$version}:".md5(json_encode($accessibleIds));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds) {
            $baseQuery = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
                ->whereIn('persons.u_id', $accessibleIds)
                ->select('hardwares.*');

            return [
                'total' => $baseQuery->count(),
                'by_type' => (clone $baseQuery)->select('type', DB::raw('count(*) as count'))
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
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:hardwares,id',
            'mark' => 'required|boolean',
        ]);

        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Single query: load accessible hardwares
        $hardwares = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
            ->whereIn('hardwares.id', $request->ids)
            ->whereIn('persons.u_id', $accessibleIds)
            ->select('hardwares.*')
            ->get();

        if ($hardwares->count() !== count($request->ids)) {
            return response()->json(['message' => 'Some hardware records are not accessible.'], 403);
        }

        $accessibleHardwareIds = $hardwares->pluck('id')->toArray();

        // Suppress individual audit entries during bulk operations
        Hardware::$suppressAudit = true;

        // Single update query on the verified IDs
        $count = Hardware::whereIn('id', $accessibleHardwareIds)
            ->update(['mark' => $request->mark]);

        // Restore audit logging
        Hardware::$suppressAudit = false;

        // Batch insert audit entries
        $this->batchInsertAudits($hardwares, 'bulk_mark', [
            ['field' => 'mark', 'old' => ! $request->mark, 'new' => $request->mark],
        ]);

        app(GisController::class)::invalidateCache();
        Hardware::flushStatsCache(); // Issue #376: bulk update bypasses Eloquent events

        return response()->json(['success' => true, 'message' => "$count device(s) updated", 'count' => $count]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:hardwares,id',
        ]);

        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Single query: load accessible hardwares
        $hardwares = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
            ->whereIn('hardwares.id', $request->ids)
            ->whereIn('persons.u_id', $accessibleIds)
            ->select('hardwares.*')
            ->get();

        if ($hardwares->count() !== count($request->ids)) {
            return response()->json(['message' => 'Some hardware records are not accessible.'], 403);
        }

        // Batch insert audit entries before deletion
        $this->batchInsertAudits($hardwares, 'bulk_delete', null, fn ($hw) => $hw->getAttributes());

        $accessibleHardwareIds = $hardwares->pluck('id')->toArray();

        // Suppress individual audit entries during bulk operations
        Hardware::$suppressAudit = true;

        $count = Hardware::whereIn('id', $accessibleHardwareIds)->delete();

        // Restore audit logging
        Hardware::$suppressAudit = false;

        app(GisController::class)::invalidateCache();
        Hardware::flushStatsCache(); // Issue #376: bulk delete bypasses Eloquent events

        return response()->json(['success' => true, 'message' => "$count device(s) deleted", 'count' => $count]);
    }

    /**
     * Batch insert audit entries in a single query instead of N+1 loop.
     */
    protected function batchInsertAudits($hardwares, string $action, ?array $staticChanges, ?\Closure $changesPerItem = null): void
    {
        $user = \Auth::user();
        $request = \Illuminate\Support\Facades\Request::capture();

        $rows = $hardwares->map(function ($hardware) use ($action, $staticChanges, $changesPerItem, $user, $request) {
            $changes = $changesPerItem ? $changesPerItem($hardware) : $staticChanges;

            return [
                'hardware_id' => $hardware->id,
                'user_id' => $user?->id,
                'action' => $action,
                'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'source' => 'bulk',
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        if (! empty($rows)) {
            HardwareAudit::insert($rows);
        }
    }
}
