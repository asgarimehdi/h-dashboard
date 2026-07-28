<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HardwareResource;
use App\Models\Hardware;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HardwareController extends Controller
{
    use PersianNormalizer;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Hardware::with('person')
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

        $sortBy = $request->get('sort_by', 'id');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) $request->get('per_page', 10), 100);
        return HardwareResource::collection($query->paginate($perPage));
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

        $hardware = Hardware::create($validated);
        $hardware->load('person');

        return response()->json([
            'success' => true,
            'data' => new HardwareResource($hardware),
        ], 201);
    }

    public function show(Hardware $hardware): JsonResponse
    {
        $hardware->load('person');
        return response()->json([
            'success' => true,
            'data' => new HardwareResource($hardware),
        ]);
    }

    public function update(Request $request, Hardware $hardware): JsonResponse
    {
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
            'data' => new HardwareResource($hardware),
        ]);
    }

    public function destroy(Hardware $hardware): JsonResponse
    {
        $hardware->delete();
        return response()->json(['success' => true, 'message' => 'حذف شد']);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total' => Hardware::count(),
                'by_type' => Hardware::selectRaw('type, count(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->toArray(),
                'shutdown' => Hardware::where('shutdown', true)->count(),
            ],
        ]);
    }

    public function bulkMark(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:hardwares,id',
            'mark' => 'required|boolean',
        ]);

        Hardware::whereIn('id', $request->ids)->update(['mark' => $request->mark]);

        return response()->json(['success' => true, 'message' => 'بروزرسانی شد']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:hardwares,id',
        ]);

        Hardware::whereIn('id', $request->ids)->delete();

        return response()->json(['success' => true, 'message' => 'حذف شدند']);
    }
}