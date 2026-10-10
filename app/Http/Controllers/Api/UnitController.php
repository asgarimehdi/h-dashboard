<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Unit;
use App\Rules\PerPage;
use App\Services\AccessService;
use App\Services\UnitDeletionService;
use Illuminate\Http\JsonResponse;

class UnitController extends Controller
{
    public function index(UnitScopedRequest $request): array
    {
        $ids = $request->accessibleIds();
        // Issue #894: `min(...)` alone bounds the upper side only. A negative
        // value is truthy, survives paginate()'s `?:` fallback and reaches
        // limit(-1), which the query builder drops — the SQL loses its LIMIT and
        // returns the whole scoped table. `PerPage` turns that into a 422, the
        // contract `ReportDays` already sets for `?days`. The `min()` stays as
        // defence in depth in case validation is ever bypassed.
        $request->validate(['per_page' => ['sometimes', new PerPage(100)]]);
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

    public function show(UnitScopedRequest $request, Unit $unit): JsonResponse
    {
        $ids = $request->accessibleIds();

        if (! in_array($unit->id, $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        return response()->json([
            'data' => $unit->load('unitType:id,name', 'region:id,name', 'parent:id,name'),
        ]);
    }

    public function store(UnitScopedRequest $request): JsonResponse
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
        $accessibleIds = $request->accessibleIds();

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

    public function update(UnitScopedRequest $request, Unit $unit): JsonResponse
    {
        $ids = $request->accessibleIds();

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
        $accessibleIds = $request->accessibleIds();

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

    public function destroy(UnitScopedRequest $request, Unit $unit): JsonResponse
    {
        $ids = $request->accessibleIds();

        if (! in_array($unit->id, $ids)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        if ($unit->children()->exists()) {
            return response()->json(['message' => 'Cannot delete unit with children.'], 422);
        }

        // #949 step 5 — parity with `units.index::deleteUnit`. This endpoint
        // used to check children only, so it was strictly weaker than the UI on
        // two counts: the six non-RESTRICT inbound FKs were invisible to it
        // (a mobile client could strip staff the web UI would have refused),
        // and the one blocker the database could see — `tickets_unit_fk` —
        // escaped as an uncaught 500 instead of a 422.
        //
        // `UnitDeletionService` is shared with the Livewire list, so "what makes
        // a unit safe to delete" has exactly one definition. The counts travel
        // with the refusal: a client that can only read a sentence cannot show
        // the user what the delete is about to cost.
        $impact = app(UnitDeletionService::class)->impact($unit);

        if (! $impact->isClear()) {
            return response()->json([
                'message' => 'Cannot delete unit.',
                'blockers' => $impact->blockers(),
            ], 422);
        }

        // Invalidate AccessService cache as hierarchy is changing
        app(AccessService::class)->clearAllCaches();

        $unit->delete();

        return response()->json(['success' => true]);
    }
}
