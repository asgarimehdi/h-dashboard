<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request): array
    {
        $ids = app(AccessService::class)->accessibleUnitIds($request->user());
        $perPage = min($request->integer('per_page', 15), 100);
        $units = Unit::whereIn('id', $ids)
            ->with('unitType:id,name')
            ->paginate($perPage);

        return [
            'data' => $units->items(),
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
            ],
        ];
    }

    public function show(Request $request, Unit $unit): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($unit->id, $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        return response()->json([
            'data' => $unit->load('unitType:id,name', 'region:id,name', 'parent:id,name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'region_id' => 'nullable|exists:regions,id',
            'parent_id' => 'nullable|exists:units,id',
            'unit_type_id' => 'required|exists:unit_types,id',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        // Check organizational scope for parent_id and region_id
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        
        if (! empty($validated['parent_id']) && ! in_array($validated['parent_id'], $accessibleIds)) {
            return response()->json(['message' => 'Parent unit not accessible.'], 403);
        }

        $unit = Unit::create($validated);

        // Invalidate AccessService cache if hierarchy changed (new child created)
        if (! empty($unit->parent_id)) {
            app(AccessService::class)->clearAllCaches();
        }

        return response()->json([
            'success' => true,
            'data' => $unit,
        ], 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($unit->id, $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'region_id' => 'nullable|exists:regions,id',
            'parent_id' => 'nullable|exists:units,id',
            'unit_type_id' => 'sometimes|required|exists:unit_types,id',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        // Check organizational scope for parent_id
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        
        if (! empty($validated['parent_id']) && ! in_array($validated['parent_id'], $accessibleIds)) {
            return response()->json(['message' => 'Parent unit not accessible.'], 403);
        }

        $oldParentId = $unit->parent_id; // Capture before update for hierarchy-change detection (#371)

        // Prevent hierarchy cycles: parent cannot be the unit itself or one of its descendants (#324)
        if (empty($validated['parent_id'])) {
            $unit->update($validated);
        } else {
            $forbiddenIds = Unit::descendantIds($unit->id)->push($unit->id)->all();
            if (in_array($validated['parent_id'], $forbiddenIds)) {
                return response()->json(['message' => 'Cannot set a descendant or self as parent (would create a cycle).'], 422);
            }
            $unit->update($validated);
        }

        // Check if hierarchy is being changed (compare against pre-update value)
        $hierarchyChanged = $request->has('parent_id') && $request->input('parent_id') !== $oldParentId;

        // Invalidate AccessService cache for all users if hierarchy changed
        if ($hierarchyChanged) {
            app(AccessService::class)->clearAllCaches();
        }

        return response()->json([
            'success' => true,
            'data' => $unit->fresh(),
        ]);
    }

    public function destroy(Request $request, Unit $unit): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($unit->id, $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        if ($unit->children()->exists()) {
            return response()->json(['message' => 'Cannot delete unit with children.'], 422);
        }

        // Invalidate AccessService cache as hierarchy is changing
        app(AccessService::class)->clearAllCaches();

        $unit->delete();

        return response()->json(['success' => true]);
    }
}
