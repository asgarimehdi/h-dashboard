<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        $query = Ticket::whereIn('unit_id', $accessibleIds)
            ->with(['unit:id,name', 'user:id,n_code', 'assignee:id,n_code']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('assigned_to_me')) {
            $query->where('current_assignee_id', $user->id);
        }

        $tickets = $query->latest()->paginate(20);

        return response()->json([
            'data' => $tickets->items(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        return response()->json([
            'data' => $ticket->load([
                'unit:id,name',
                'user:id,n_code',
                'assignee:id,n_code',
                'activities' => fn ($q) => $q->latest()->with('user:id,n_code'),
                'attachments',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'priority' => 'required|in:urgent,high,normal,medium,low',
            'unit_id' => 'required|exists:units,id',
            'deadline' => 'nullable|date',
        ]);

        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($validated['unit_id'], $accessibleIds)) {
            return response()->json(['message' => 'Unit not accessible.'], 403);
        }

        $ticket = Ticket::create([
            ...$validated,
            'ticket_code' => 'T-' . strtoupper(Str::random(8)),
            'user_id' => $request->user()->id,
            'status' => 'created',
        ]);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ], 201);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $validated = $request->validate([
            'subject' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'priority' => 'sometimes|required|in:urgent,high,normal,medium,low',
            'deadline' => 'nullable|date',
        ]);

        $ticket->update($validated);

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh(),
        ]);
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $ticket->delete();

        return response()->json(['success' => true]);
    }

    public function assign(Request $request, Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $validated = $request->validate([
            'assignee_id' => 'required|exists:users,id',
        ]);

        $ticket->update([
            'current_assignee_id' => $validated['assignee_id'],
            'status' => 'forwarded',
        ]);

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh(),
        ]);
    }

    public function accept(Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $ticket->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh(),
        ]);
    }

    public function complete(Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        if ($ticket->status !== 'accepted') {
            return response()->json(['message' => 'Ticket must be accepted before completing.'], 422);
        }

        $ticket->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh(),
        ]);
    }
}
