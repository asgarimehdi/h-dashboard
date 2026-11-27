<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * #1699 (phase 1): the single implementation of the accept/complete ticket
 * transitions.
 *
 * Before this service, `Api\TicketController::accept()/complete()` and
 * `TicketsInboxFlow::acceptTicket()` / the completed branch of
 * `submitAction()` carried the same guards twice, kept in step only by
 * "mirrors" comments (#1564, #1609, #1620, #1675, #1686, #1662). Phase 2 moves
 * reject/forward/assign/update here too.
 *
 * Contract (binding, see issue #1699 gate):
 *  - The lock is the SAME raw spelling both test harnesses intercept:
 *    `SELECT ... FOR UPDATE` inside `DB::transaction`. Eloquent
 *    `lockForUpdate()` is NOT equivalent to `fakeLockedTicketRow()`.
 *  - `unit_id` is never read from the locked row: the API test's fake row does
 *    not carry it, and the web path must use the model's own column.
 *  - `archived_at` is only read as `$row->archived_at ?? null`: the web accept
 *    fake row omits the column.
 *  - One lock per transition. The completed branch of `submitAction()` used to
 *    take two; this service takes one.
 *  - The per-method status rule is preserved exactly:
 *      accept  — locked status equals the status that was read, and that
 *                status is one of `created`/`forwarded`;
 *      complete — locked status is `accepted`.
 *  - The service never calls `auth()`, `session()` or `Storage`. The actor id
 *    is a parameter (#836); the adapters keep permission checks, request
 *    validation, scoped reads, file storage and user-facing messages.
 *  - `ActivityLog` is unchanged by phase 1 (D4): `$audit` exists only so the
 *    web accept path can keep writing its audit row inside the same
 *    transaction.
 *  - #1909: `accept()`/`complete()` notify the ticket creator through
 *    `SendNotificationJob` (types `ticket_accepted`/`ticket_completed`),
 *    skipped when the creator is null or is the actor. The dispatch is
 *    deferred with `DB::afterCommit` because both methods run inside
 *    `DB::transaction`: a rolled-back transition must never notify.
 */
final class TicketTransitionService
{
    /**
     * Accept a ticket on behalf of its current assignee.
     *
     * @param  bool  $audit  web path only: also write an `activity_logs` row (D4)
     */
    public function accept(Ticket $ticket, int $actorId, bool $audit = false): TicketTransitionResult
    {
        return DB::transaction(function () use ($ticket, $actorId, $audit): TicketTransitionResult {
            $row = $this->lockTicket($ticket->id);

            $failures = $this->acceptFailures($ticket, $row, $actorId);

            if ($failures !== []) {
                return TicketTransitionResult::failed($failures);
            }

            $oldStatus = $ticket->status;

            $ticket->update([
                'status' => 'accepted',
                'current_assignee_id' => $actorId,
                'accepted_at' => now(),
            ]);

            $ticket->activities()->create([
                'user_id' => $actorId,
                'action' => 'accepted',
                'description' => 'تیکت توسط کارشناس تایید شد و مسئولیت آن پذیرفته شد.',
            ]);

            if ($audit) {
                ActivityLogService::updated(
                    $ticket,
                    ['status' => $oldStatus],
                    ['status' => 'accepted'],
                    "پذیرش تیکت {$ticket->ticket_code}"
                );
            }

            $this->notifyCreator($ticket, $actorId, 'ticket_accepted', 'تیکت شما پذیرفته شد', 'توسط کارشناس پذیرفته شد');

            return TicketTransitionResult::success($ticket);
        });
    }

    /**
     * Complete an accepted ticket on behalf of its current assignee.
     *
     * @param  list<array{path: string, file_name: string, file_size: int}>  $attachments  files already on disk (the adapter stores them before this call)
     */
    public function complete(Ticket $ticket, int $actorId, string $note = '', array $attachments = []): TicketTransitionResult
    {
        return DB::transaction(function () use ($ticket, $actorId, $note, $attachments): TicketTransitionResult {
            $row = $this->lockTicket($ticket->id);

            $failures = $this->completeFailures($ticket, $row, $actorId);

            if ($failures !== []) {
                return TicketTransitionResult::failed($failures);
            }

            $ticket->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $todoCompleted = false;

            // #1609: this runs AFTER the locked re-check and reads sibling
            // tickets, which the row lock does not cover. Locking the siblings
            // is deliberately not attempted: two concurrent completions of the
            // last open tickets both marking the task complete is the same
            // benign outcome.
            if ($ticket->task_id) {
                // #1728: the shared OPEN predicate, so a rejected (terminal)
                // sibling no longer keeps the parent task open forever.
                $openSiblings = Ticket::query()
                    ->where('task_id', $ticket->task_id)
                    ->open()
                    ->where('id', '!=', $ticket->id)
                    ->count();

                if ($openSiblings === 0) {
                    $task = $ticket->task;

                    if ($task !== null) {
                        $task->update(['is_completed' => true]);
                        $todoCompleted = true;
                    }
                }
            }

            $activity = $ticket->activities()->create([
                'user_id' => $actorId,
                'action' => 'completed',
                'description' => $note !== ''
                    ? "تیکت مختومه شد. گزارش نهایی: {$note}"
                    : 'تیکت مختومه شد.',
                'to_unit_id' => $ticket->unit_id,
            ]);

            foreach ($attachments as $stored) {
                $ticket->attachments()->create([
                    'user_id' => $actorId,
                    'activity_id' => $activity->id,
                    'file_path' => $stored['path'],
                    'file_name' => $stored['file_name'],
                    'file_size' => $stored['file_size'],
                ]);
            }

            $this->notifyCreator($ticket, $actorId, 'ticket_completed', 'تیکت شما مختومه شد', 'مختومه شد');

            return TicketTransitionResult::success($ticket, $todoCompleted);
        });
    }

    /**
     * #1909: the creator is the one party no other channel informs.
     * Deferred with afterCommit: a rolled-back transition (or a later failure
     * in the same transaction) must never notify. Production runs the `redis`
     * queue (.env.example), so an in-transaction dispatch would be visible to
     * a worker before the row it describes is committed.
     */
    private function notifyCreator(Ticket $ticket, int $actorId, string $type, string $title, string $verb): void
    {
        $creatorId = $ticket->user_id;

        if ($creatorId === null || (int) $creatorId === $actorId) {
            return;
        }

        $creatorId = (int) $creatorId;
        $ticketId = (int) $ticket->id;
        $code = (string) $ticket->ticket_code;
        $subject = (string) $ticket->subject;

        DB::afterCommit(function () use ($creatorId, $type, $title, $verb, $ticketId, $code, $subject): void {
            SendNotificationJob::dispatch(
                $creatorId,
                $type,
                $title,
                "تیکت #{$code} {$verb} - موضوع: {$subject}",
                'o-ticket',
                'text-info',
                route('tickets.inbox', $ticketId),
                ['ticket_id' => $ticketId],
            );
        });
    }

    /**
     * #929/#1683: the one closed-ticket definition. An `archived_at` timestamp
     * counts as closed even while the status still reads an open value.
     */
    public static function isClosed(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        return in_array($row->status, ['completed', 'rejected'], true)
            || ($row->archived_at ?? null) !== null;
    }

    /**
     * The raw lock both harnesses (`TicketApiTest::fakeLockedTicketRow`,
     * `TicketsInboxLivewireTest::fakeLockedTicketRow`) intercept. Keep the
     * column list to what the predicates read, and never add `unit_id`.
     */
    private function lockTicket(int $ticketId): ?object
    {
        $locked = DB::select(
            'SELECT id, status, current_assignee_id, archived_at FROM tickets WHERE id = ? FOR UPDATE',
            [$ticketId]
        );

        return $locked[0] ?? null;
    }

    /**
     * @return list<TicketTransitionFailure>
     */
    private function acceptFailures(Ticket $ticket, ?object $row, int $actorId): array
    {
        if ($row === null) {
            return [TicketTransitionFailure::Missing];
        }

        $failures = [];

        $readStatus = (string) $ticket->status;

        if (! in_array($readStatus, ['created', 'forwarded'], true)
            || (string) $row->status !== $readStatus) {
            $failures[] = TicketTransitionFailure::StatusMismatch;
        }

        if ((int) $ticket->current_assignee_id !== $actorId) {
            $failures[] = TicketTransitionFailure::AssigneeMismatch;
        }

        if ((int) $row->current_assignee_id !== (int) $ticket->current_assignee_id) {
            $failures[] = TicketTransitionFailure::AssigneeRaced;
        }

        if (self::isClosed($row)) {
            $failures[] = TicketTransitionFailure::Closed;
        }

        return $failures;
    }

    /**
     * @return list<TicketTransitionFailure>
     */
    private function completeFailures(Ticket $ticket, ?object $row, int $actorId): array
    {
        if ($row === null) {
            return [TicketTransitionFailure::Missing];
        }

        $failures = [];

        if (self::isClosed($row)) {
            $failures[] = TicketTransitionFailure::Closed;
        }

        if ((string) $row->status !== 'accepted') {
            $failures[] = TicketTransitionFailure::StatusMismatch;
        }

        if ((int) $ticket->current_assignee_id !== $actorId) {
            $failures[] = TicketTransitionFailure::AssigneeMismatch;
        }

        if ((int) $row->current_assignee_id !== (int) $ticket->current_assignee_id) {
            $failures[] = TicketTransitionFailure::AssigneeRaced;
        }

        return $failures;
    }
}
