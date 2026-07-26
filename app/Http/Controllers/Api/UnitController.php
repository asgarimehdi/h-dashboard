<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(): array
    {
        $ids = app(AccessService::class)->accessibleUnitIds();
        $units = Unit::whereIn('id', $ids)
            ->with('unitType:id,name')
            ->paginate();

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

    public function show(Unit $unit): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

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

        $unit = Unit::create($validated);

        return response()->json([
            'success' => true,
            'data' => $unit,
        ], 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

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

        $unit->update($validated);

        return response()->json([
            'success' => true,
            'data' => $unit->fresh(),
        ]);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $ids = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($unit->id, $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        if ($unit->children()->exists()) {
            return response()->json(['message' => 'Cannot delete unit with children.'], 422);
        }

        $unit->delete();

        return response()->json(['success' => true]);
    }
}
