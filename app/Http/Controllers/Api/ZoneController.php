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
    public function index(): JsonResponse
    {
        $zones = Zone::withCount('units')->get();

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

    public function show(Zone $zone): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $zone->load('units:id,name'),
        ]);
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'unit_ids' => 'nullable|array',
            'unit_ids.*' => 'exists:units,id',
        ]);

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

    public function destroy(Zone $zone): JsonResponse
    {
        $zone->units()->detach();
        $zone->delete();

        return response()->json(['success' => true]);
    }
}