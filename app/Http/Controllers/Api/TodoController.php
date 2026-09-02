<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Http\Resources\TodoResource;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TodoController extends Controller
{
    public function index(UnitScopedRequest $request): AnonymousResourceCollection
    {
        $accessibleIds = $request->accessibleIds();

        $query = Todo::whereIn('unit_id', $accessibleIds)->with('unit:id,name');

        if ($request->filled('date')) {
            $query->whereDate('start_at', $request->date);
        }

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('start_at', $request->month)
                ->whereYear('start_at', $request->year);
        }

        if ($request->filled('is_completed')) {
            $query->where('is_completed', $request->boolean('is_completed'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $todos = $query->latest()->paginate($perPage);

        return TodoResource::collection($todos);
    }

    public function store(UnitScopedRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|min:3',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'is_completed' => 'boolean',
            'unit_id' => 'nullable|exists:units,id',
        ]);

        $user = $request->user();

        // Explicit unit_id wins; fall back to the user's primary unit (pivot is_primary),
        // then to the person's unit — never silently null (#431)
        $unitId = $validated['unit_id'] ?? $user->primaryUnit()?->id ?? $user->person?->u_id;

        if (! $unitId || ! in_array($unitId, $request->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized to create todo in this unit.'], 403);
        }

        $todo = Todo::create([
            'title' => $validated['title'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'] ?? null,
            'is_completed' => $validated['is_completed'] ?? false,
            'unit_id' => $unitId,
        ]);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo->load('unit')),
        ], 201);
    }

    public function show(UnitScopedRequest $request, Todo $todo): JsonResponse
    {
        if (! $todo->unit_id || ! in_array($todo->unit_id, $request->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo->load('unit')),
        ]);
    }

    public function update(UnitScopedRequest $request, Todo $todo): JsonResponse
    {
        if (! $todo->unit_id || ! in_array($todo->unit_id, $request->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized to update this todo.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|min:3',
            'start_at' => 'sometimes|required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'is_completed' => 'boolean',
        ]);

        $todo->update($validated);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo->load('unit')),
        ]);
    }

    public function destroy(UnitScopedRequest $request, Todo $todo): JsonResponse
    {
        if (! $todo->unit_id || ! in_array($todo->unit_id, $request->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized to delete this todo.'], 403);
        }

        $todo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Todo deleted successfully',
        ]);
    }

    public function toggleComplete(UnitScopedRequest $request, Todo $todo): JsonResponse
    {
        if (! $todo->unit_id || ! in_array($todo->unit_id, $request->accessibleIds())) {
            return response()->json(['message' => 'Unauthorized to modify this todo.'], 403);
        }

        $todo->update(['is_completed' => ! $todo->is_completed]);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo->load('unit')),
        ]);
    }
}
