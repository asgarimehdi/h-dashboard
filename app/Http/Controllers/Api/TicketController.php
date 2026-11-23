<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\TicketTargetUnit;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    /**
     * #1608: outcomes of atomicTransition(). The caller maps them to the
     * existing 403/422 responses — the Flutter app depends on those codes and
     * messages, so the helper must not collapse assignee drift into status
     * drift.
     */
    private const TRANSITION_OK = 'ok';

    private const TRANSITION_ASSIGNEE_MISMATCH = 'assignee-mismatch';

    private const TRANSITION_STATUS_MISMATCH = 'status-mismatch';

    private const TRANSITION_CLOSED = 'closed';

    private const TRANSITION_MISSING = 'missing';

    public function index(UnitScopedRequest $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => 'sometimes|in:created,forwarded,accepted,completed,rejected',
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
        ]);

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

        return TicketResource::collection($tickets);
    }

    public function show(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        return response()->json([
            'data' => new TicketDetailResource($ticket->load([
                'unit:id,name',
                'user:id,n_code',
                'assignee:id,n_code',
                'activities' => fn ($q) => $q->latest()->with('user:id,n_code'),
                'attachments',
            ])),
        ]);
    }

    public function store(UnitScopedRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string|max:20000',
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'unit_id' => ['required', 'integer', new TicketTargetUnit],
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
            'data' => new TicketDetailResource($ticket->load(['unit:id,name'])),
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
            'content' => 'sometimes|required|string|max:20000',
            'priority' => ['sometimes', 'required', Rule::in(Ticket::PRIORITIES)],
            'deadline' => 'nullable|date',
        ]);

        // #1633: a closed ticket is immutable. Same locked-row guard as assign()
        // (#1608) — a pre-check on the bound model would leave the same
        // check-then-act window a concurrent complete()/reject() can slip through.
        $transition = $this->atomicTransition($ticket, [], $validated, rejectClosed: true);

        if ($transition === self::TRANSITION_MISSING) {
            return response()->json(['message' => 'Ticket no longer exists.'], 422);
        }

        if ($transition !== self::TRANSITION_OK) {
            return response()->json(['message' => 'Closed tickets cannot be edited.'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TicketDetailResource($ticket->fresh()->load(['unit:id,name'])),
        ]);
    }

    public function destroy(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $request->assertAccessibleUnit($ticket->unit_id);
        if ($result !== true) {
            return $result;
        }

        // #1683: a closed ticket is not deletable — the same one-way rule (#929)
        // every other ticket write enforces (#1608, #1633). The check and the
        // delete share ONE transaction on the locked row; a pre-check followed
        // by a delete outside the lock would reopen the check-then-act window a
        // concurrent complete()/reject()/archive can slip through.
        $outcome = $this->atomicDelete($ticket);

        if ($outcome === self::TRANSITION_MISSING) {
            return response()->json(['message' => 'Ticket no longer exists.'], 422);
        }

        if ($outcome !== self::TRANSITION_OK) {
            return response()->json(['message' => 'Closed tickets cannot be deleted.'], 422);
        }

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

        // #1608: this write sets status = 'forwarded' unconditionally, so on a
        // closed ticket it silently resurrects it and leaves the stale
        // completed_at / accepted_at / archived_at behind. Archiving is one-way
        // (#929), so the guard runs on the locked row — a pre-check on the
        // bound model would leave the same check-then-act window.
        $transition = $this->atomicTransition(
            $ticket,
            [],
            ['current_assignee_id' => $validated['assignee_id'], 'status' => 'forwarded'],
            rejectClosed: true,
        );

        if ($transition === self::TRANSITION_MISSING) {
            return response()->json(['message' => 'Ticket no longer exists.'], 422);
        }

        if ($transition !== self::TRANSITION_OK) {
            return response()->json(['message' => 'Closed tickets cannot be reassigned.'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TicketDetailResource($ticket->fresh()->load(['unit:id,name', 'assignee:id,n_code'])),
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

        // Issue #1000: accepting only makes sense from an open status. Keep this
        // guard in step with the web path (⚡inbox.blade.php, which allows the
        // same two statuses).
        if (! in_array($ticket->status, ['created', 'forwarded'], true)) {
            return response()->json(['message' => 'Only created or forwarded tickets can be accepted.'], 422);
        }

        // #1608: the checks above read the bound model. Re-check the same claim
        // on the locked row, so a concurrent accept(), an assign()/forward()
        // that moved the assignee (without changing status) or a DELETE between
        // the read and the write is a loss instead of a silent overwrite. Same
        // shape as TicketsInboxFlow::acceptTicket() (#1564).
        $transition = $this->atomicTransition(
            $ticket,
            ['status' => $ticket->status, 'current_assignee_id' => $request->user()->id],
            ['status' => 'accepted', 'accepted_at' => now()],
        );

        if ($transition === self::TRANSITION_ASSIGNEE_MISMATCH) {
            return response()->json(['message' => 'Only the assigned user can accept this ticket.'], 403);
        }

        if ($transition !== self::TRANSITION_OK) {
            return response()->json(['message' => 'Only created or forwarded tickets can be accepted.'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TicketDetailResource($ticket->fresh()->load(['unit:id,name', 'assignee:id,n_code'])),
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

        // #1608: same locked re-check as accept() — completion after a
        // concurrent reassignment is a loss (403), and a status that moved on
        // is a 422, never a silent double-complete.
        $transition = $this->atomicTransition(
            $ticket,
            ['status' => 'accepted', 'current_assignee_id' => $request->user()->id],
            ['status' => 'completed', 'completed_at' => now()],
        );

        if ($transition === self::TRANSITION_ASSIGNEE_MISMATCH) {
            return response()->json(['message' => 'Only the assigned user can complete this ticket.'], 403);
        }

        if ($transition !== self::TRANSITION_OK) {
            return response()->json(['message' => 'Ticket must be accepted before completing.'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TicketDetailResource($ticket->fresh()->load(['unit:id,name', 'assignee:id,n_code'])),
        ]);
    }

    /**
     * #1608: perform a ticket write atomically.
     *
     * The controller validates the bound model first (fast path, and the source
     * of the 403/422 messages), then this helper re-reads the row under
     * `SELECT ... FOR UPDATE` inside a transaction and re-checks it against the
     * values the caller already validated. Anything that moved between the two
     * reads — a concurrent transition, an assignee change, a closed ticket, a
     * DELETEd row — is a loss, never a silent overwrite. The query shape
     * mirrors the web `TicketsInboxFlow::acceptTicket()` (#1564, PR #1568).
     *
     * @param  array{status?: string, current_assignee_id?: int}  $expected
     * @param  array<string, mixed>  $attributes
     * @return string one of the TRANSITION_* constants
     */
    private function atomicTransition(Ticket $ticket, array $expected, array $attributes, bool $rejectClosed = false): string
    {
        return DB::transaction(function () use ($ticket, $expected, $attributes, $rejectClosed): string {
            $locked = DB::select(
                'SELECT id, status, current_assignee_id, archived_at FROM tickets WHERE id = ? FOR UPDATE',
                [$ticket->id]
            );

            // The row was DELETEd between route-model binding and the lock.
            if ($locked === []) {
                return self::TRANSITION_MISSING;
            }

            $row = $locked[0];

            if (array_key_exists('current_assignee_id', $expected)
                && (int) $row->current_assignee_id !== (int) $expected['current_assignee_id']) {
                return self::TRANSITION_ASSIGNEE_MISMATCH;
            }

            // #929: archiving is one-way — a closed ticket stays closed unless a
            // human reopens it deliberately. Checked on the locked row so a
            // concurrent complete() cannot land after a stale pre-check.
            if ($rejectClosed && $this->isClosedRow($row)) {
                return self::TRANSITION_CLOSED;
            }

            if (array_key_exists('status', $expected) && $row->status !== $expected['status']) {
                return self::TRANSITION_STATUS_MISMATCH;
            }

            $ticket->update($attributes);

            return self::TRANSITION_OK;
        });
    }

    /**
     * #1683: delete a ticket atomically.
     *
     * Same locked-row shape as atomicTransition() (#1608) — the raw
     * `DB::select(... FOR UPDATE)` is deliberate: the test harness
     * (TicketApiTest::fakeLockedTicketRow) only intercepts that spelling, and the
     * delete MUST happen inside the same transaction as the guard.
     *
     * @return string one of the TRANSITION_* constants
     */
    private function atomicDelete(Ticket $ticket): string
    {
        return DB::transaction(function () use ($ticket): string {
            $locked = DB::select(
                'SELECT id, status, unit_id, archived_at FROM tickets WHERE id = ? FOR UPDATE',
                [$ticket->id]
            );

            if ($locked === []) {
                return self::TRANSITION_MISSING;
            }

            $row = $locked[0];

            if ($this->isClosedRow($row)) {
                return self::TRANSITION_CLOSED;
            }

            $ticket->delete();

            // activity_logs has no FK to subject_id, so this row outlives the
            // ticket and its cascaded comments/attachments/task_activities.
            // Same order as DiseaseCaseService::delete().
            ActivityLogService::log(
                'deleted',
                $ticket,
                "حذف تیکت {$ticket->ticket_code}",
                ['status' => $row->status, 'unit_id' => $row->unit_id],
                null,
            );

            return self::TRANSITION_OK;
        });
    }

    /**
     * #1683: the single closed-ticket definition shared by every ticket write
     * (#1608, #1633). #929: archiving is one-way — an `archived_at` timestamp
     * counts as closed even while the status still reads an open value.
     */
    private function isClosedRow(object $row): bool
    {
        return in_array($row->status, ['completed', 'rejected'], true) || $row->archived_at !== null;
    }
}
