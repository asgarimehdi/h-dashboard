<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TodoResource;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TodoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Todo::with(['creator.person', 'users.person'])
            ->where('unit_id', $request->user()->person?->u_id);

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

        if ($request->filled('user_id')) {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $request->user_id));
        }

        $todos = $query->latest()->paginate($request->input('per_page', 15));

        return TodoResource::collection($todos);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|min:3',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'is_completed' => 'boolean',
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $todo = Todo::create([
            'title' => $validated['title'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'] ?? null,
            'is_completed' => $validated['is_completed'] ?? false,
            'unit_id' => $request->user()->person?->u_id,
            'user_id' => $request->user()->id,
        ]);

        $todo->users()->sync($validated['user_ids']);

        $todo->load(['creator.person', 'users.person']);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo),
        ], 201);
    }

    public function show(Todo $todo): JsonResponse
    {
        $todo->load(['creator.person', 'users.person']);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo),
        ]);
    }

    public function update(Request $request, Todo $todo): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|min:3',
            'start_at' => 'sometimes|required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'is_completed' => 'boolean',
            'user_ids' => 'sometimes|required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $todo->update(collect($validated)->except('user_ids')->toArray());

        if (isset($validated['user_ids'])) {
            $todo->users()->sync($validated['user_ids']);
        }

        $todo->load(['creator.person', 'users.person']);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo),
        ]);
    }

    public function destroy(Todo $todo): JsonResponse
    {
        $todo->users()->detach();
        $todo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Todo deleted successfully',
        ]);
    }

    public function toggleComplete(Todo $todo): JsonResponse
    {
        $todo->update(['is_completed' => ! $todo->is_completed]);

        $todo->load(['creator.person', 'users.person']);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo),
        ]);
    }

    public function assignUsers(Request $request, Todo $todo): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $todo->users()->sync($validated['user_ids']);

        $todo->load(['creator.person', 'users.person']);

        return response()->json([
            'success' => true,
            'data' => new TodoResource($todo),
        ]);
    }
}
