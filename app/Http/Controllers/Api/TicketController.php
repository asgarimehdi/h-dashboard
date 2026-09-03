<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index(UnitScopedRequest $request): JsonResponse
    {
        $user = $request->user();

        $query = Ticket::whereIn('unit_id', $request->accessibleIds())
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

    public function show(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
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

    public function store(UnitScopedRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'priority' => 'required|in:low,normal,urgent',
            'unit_id' => 'required|exists:units,id',
            'deadline' => 'nullable|date',
        ]);

        $result = $request->assertAccessibleUnit($validated['unit_id']);
        if ($result !== true) {
            return $result;
        }

        $ticket = Ticket::create([
            ...$validated,
            'ticket_code' => 'T-'.strtoupper(Str::random(8)),
            'user_id' => $request->user()->id,
            'status' => 'created',
        ]);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ], 201);
    }

    public function update(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        $validated = $request->validate([
            'subject' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'priority' => 'sometimes|required|in:low,normal,urgent',
            'deadline' => 'nullable|date',
        ]);

        $ticket->update($validated);

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh(),
        ]);
    }

    public function destroy(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        $ticket->delete();

        return response()->json(['success' => true]);
    }

    public function assign(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        $validated = $request->validate([
            'assignee_id' => 'required|exists:users,id',
        ]);

        $assignee = User::find($validated['assignee_id']);

        // Issue #205: the assignee must belong to the ticket's organizational scope
        $assigneeUnitIds = $assignee->units()->pluck('units.id')->toArray();
        $assigneeUnitIds[] = $assignee->person?->u_id;

        if (empty(array_intersect($assigneeUnitIds, $request->accessibleIds()))) {
            return response()->json(['message' => 'Assignee is not in an accessible unit.'], 403);
        }

        $ticket->update([
            'current_assignee_id' => $validated['assignee_id'],
            'status' => 'forwarded',
        ]);

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh(),
        ]);
    }

    public function accept(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        // Issue #529: only the assigned user can accept the ticket
        if ($ticket->current_assignee_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the assigned user can accept this ticket.'], 403);
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

    public function complete(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        // Issue #530: only the assigned user can complete the ticket
        if ($ticket->current_assignee_id !== $request->user()->id) {
            return response()->json(['message' => 'Only the assigned user can complete this ticket.'], 403);
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
