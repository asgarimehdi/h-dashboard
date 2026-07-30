<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    /**
     * Check if the given zone is within the user's accessible organizational scope.
     */
    private function assertAccessible(Request $request, Zone $zone): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        if (empty($accessibleIds)) {
            abort(403, 'Zone not accessible.');
        }

        $zoneUnitIds = $zone->units()->pluck('zone_units.unit_id')->toArray();

        if (empty(array_intersect($zoneUnitIds, $accessibleIds))) {
            abort(403, 'Zone not accessible.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $zones = Zone::accessible($request->user())
            ->withCount('units')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $zones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'exists:units,id',
        ]);

        // Validate that all unit_ids are within the user's organizational scope
        if (! empty($validated['unit_ids'])) {
            $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
            $invalidIds = array_diff($validated['unit_ids'], $accessibleIds);

            if (! empty($invalidIds)) {
                return response()->json([
                    'message' => 'One or more unit_ids are outside your organizational scope.',
                    'invalid_ids' => array_values($invalidIds),
                ], 403);
            }
        }

        $zone = Zone::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? null,
        ]);

        if (! empty($validated['unit_ids'])) {
            $zone->units()->sync($validated['unit_ids']);
        }

        return response()->json([
            'success' => true,
            'data' => $zone->load('units'),
        ], 201);
    }

    public function show(Request $request, Zone $zone): JsonResponse
    {
        $this->assertAccessible($request, $zone);

        return response()->json([
            'success' => true,
            'data' => $zone->load('units:id,name'),
        ]);
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        $this->assertAccessible($request, $zone);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'exists:units,id',
        ]);

        // Validate that all unit_ids are within the user's organizational scope
        if (array_key_exists('unit_ids', $validated) && ! empty($validated['unit_ids'])) {
            $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
            $invalidIds = array_diff($validated['unit_ids'], $accessibleIds);

            if (! empty($invalidIds)) {
                return response()->json([
                    'message' => 'One or more unit_ids are outside your organizational scope.',
                    'invalid_ids' => array_values($invalidIds),
                ], 403);
            }
        }

        $zone->update([
            'name' => $validated['name'] ?? $zone->name,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $zone->description,
            'color' => array_key_exists('color', $validated) ? $validated['color'] : $zone->color,
        ]);

        if (array_key_exists('unit_ids', $validated)) {
            $zone->units()->sync($validated['unit_ids'] ?? []);
        }

        return response()->json([
            'success' => true,
            'data' => $zone->fresh()->load('units:id,name'),
        ]);
    }

    public function destroy(Request $request, Zone $zone): JsonResponse
    {
        $this->assertAccessible($request, $zone);

        $zone->units()->detach();
        $zone->delete();

        return response()->json(['success' => true]);
    }
}