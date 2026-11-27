<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Jobs\SendNotificationJob;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\TicketTargetUnit;
use App\Services\ActivityLogService;
use App\Services\TicketTransitionFailure;
use App\Services\TicketTransitionService;
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

    /**
     * #1751: explicit column list for the ticket LIST payload. `content` is a
     * TEXT column (up to 20000 chars on write) that `TicketResource` never
     * returns — the list resource deliberately omits it (#1189). `task_id`,
     * `schedule_id` and `archived_at` are internal bookkeeping and equally
     * unread here. Field names in the payload are unchanged.
     *
     * Must stay in sync with TicketResource::toArray() and keep every key an
     * eager load matches on (`user_id`, `unit_id`, `current_assignee_id`) —
     * a missing FK column silently nulls the relation.
     */
    private const TICKET_LIST_COLUMNS = [
        'id', 'ticket_code', 'subject', 'priority', 'status',
        'user_id', 'unit_id', 'current_assignee_id',
        'deadline', 'accepted_at', 'completed_at', 'created_at', 'updated_at',
    ];

    public function index(UnitScopedRequest $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => 'sometimes|in:created,forwarded,accepted,completed,rejected',
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
        ]);

        $user = $request->user();

        $query = Ticket::whereIn('unit_id', $request->accessibleIds())
            ->select(self::TICKET_LIST_COLUMNS)
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
            'assignee_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ]);

        $assignee = User::find($validated['assignee_id']);

        // #1857: defense in depth. Step 1 already rejects a deactivated id, but a
        // soft delete landing between the validation query and this lookup would
        // leave $assignee null and turn the #205 scope check below into a 500.
        if ($assignee === null) {
            return response()->json(['message' => 'Assignee is deactivated or does not exist.'], 422);
        }

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

        // #1909: the assignee is the only party this hand-off never told.
        // No afterCommit wrapper: atomicTransition() has already committed here.
        if ((int) $validated['assignee_id'] !== (int) $request->user()->id) {
            SendNotificationJob::dispatch(
                (int) $validated['assignee_id'],
                'ticket_assigned',
                'تیکت به شما ارجاع شد',
                "تیکت #{$ticket->ticket_code} به شما ارجاع شد - موضوع: {$ticket->subject}",
                'o-ticket',
                'text-info',
                route('tickets.inbox', $ticket->id),
                ['ticket_id' => (int) $ticket->id],
            );
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

        // #1699: the guards, the locked-row re-check and the TaskActivity
        // fan-out live in TicketTransitionService. This adapter only decides
        // which existing 403/422 the Flutter app receives.
        $transition = app(TicketTransitionService::class)->accept($ticket, (int) $request->user()->id);

        if (! $transition->ok) {
            // Issue #529: only the assigned user can accept the ticket. The
            // assignee verdict wins over the status one (#1608 ordering).
            if ($transition->has(TicketTransitionFailure::AssigneeMismatch, TicketTransitionFailure::AssigneeRaced)) {
                return response()->json(['message' => 'Only the assigned user can accept this ticket.'], 403);
            }

            // Issue #1000: accepting only makes sense from an open status.
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

        $transition = app(TicketTransitionService::class)->complete($ticket, (int) $request->user()->id);

        if (! $transition->ok) {
            // Issue #530: only the assigned user can complete the ticket.
            if ($transition->has(TicketTransitionFailure::AssigneeMismatch, TicketTransitionFailure::AssigneeRaced)) {
                return response()->json(['message' => 'Only the assigned user can complete this ticket.'], 403);
            }

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
     * DELETEd row — is a loss, never a silent overwrite.
     *
     * Accept/complete no longer use this helper: they delegate to
     * TicketTransitionService (#1699). It stays for `update()`/`assign()`.
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
     *
     * #1699: the definition now lives in TicketTransitionService, next to the
     * transitions that enforce it.
     */
    private function isClosedRow(object $row): bool
    {
        return TicketTransitionService::isClosed($row);
    }
}
