<?php

namespace App\Traits;

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Rules\TicketTargetUnit;
use App\Services\AccessService;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\ActorLabel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * The `tickets.inbox` detail-modal + assignment/forward/completion flow
 * (#1055, step 2).
 *
 * `⚡inbox.blade.php` carried the whole ticket lifecycle inline — the detail
 * modal, the destination-unit picker, forward/accept/reject, the
 * completion-or-forward modal and its attachments — on top of the list,
 * filters and bulk actions, in one 1064-line anonymous class. Every ticket
 * change landed in that file, and the assignment paths repeated the same
 * scaffolding (`accessibleUnitIds()` lookup, target-unit resolution, activity +
 * notification fan-out).
 *
 * The flow moves here as one unit of state: Livewire public properties cannot
 * be handed between components, so a trait keeps `$targetUnitId`,
 * `$completionNote`, `$completionFiles` and `$showingTicket` in the *same*
 * component instance, bound to the *same* Blade view. Only the state whose
 * owner is the flow lives here — the `#[Url]` filter properties (`search`,
 * `unitSearch`, `forwardNote`, the date window, `statusFilter`, `viewMode`,
 * `currentTab`) stay in the component so their URL binding and the
 * filter-handler methods remain in one place.
 *
 * The component keeps the list, the filters and the bulk actions; this trait
 * owns one ticket at a time.
 */
trait TicketsInboxFlow
{
    /**
     * Issue #818: the routing target is deliberately NOT a #[Url] property — it
     * was settable from the query string, so a crafted link pre-selected a unit
     * the user never picked. It is only ever set through selectTargetUnit(),
     * which resolves the name itself.
     */
    public ?int $targetUnitId = null;

    public string $targetUnitName = '';

    public bool $isCompletionModalOpen = false;

    public string $completionNote = '';

    public array $completionFiles = [];

    /**
     * #1176: the detail-modal ticket is no longer a public model prop. A
     * public `?Ticket` dehydrates by key, and Livewire re-fetches it WITHOUT
     * relations on the next update, so the modal's eager loads were gone
     * after every wire round-trip (activities, their attachments and their
     * actor lazy-loaded row by row). The id stays public; the model is the
     * `#[Computed]` below and is re-derived per request.
     */
    public ?int $showingTicketId = null;

    public bool $showModal = false;

    /**
     * #1176: how much of the activity history the detail modal pays for.
     * Long-lived tickets rendered their entire `task_activities` history on
     * every open, unbounded; the modal keeps the newest slice and the
     * newest-first display order sorts it.
     */
    private const ACTIVITY_HISTORY_LIMIT = 50;

    /**
     * The detail-modal ticket, re-derived per request.
     *
     * Same access shape as the load it replaces (#1055): the scope predicate
     * is an UNCONDITIONAL `whereIn('unit_id', $accessibleIds)` — an empty
     * accessible set compiles to `0 = 1`, in scope of nothing, never
     * unrestricted — plus the #847 gate on the `task` relation below. For the
     * same request the result is cached by `#[Computed]`; after a mutation
     * the request closes the modal anyway, and any explicit re-read invalidates
     * with `unset($this->showingTicket)`.
     */
    #[Computed]
    public function showingTicket(): ?Ticket
    {
        if (! $this->showingTicketId) {
            return null;
        }

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // #906: soft-deleted users are kept in the result so their creator
        // renders «کاربر غیرفعال» instead of collapsing into the «سیستم» a
        // truly ownerless ticket gets. Column list on `attachments` keeps the
        // payload to what the modal renders (#1176). `with()` leads the chain
        // and the IN-filter sits inside `where(Closure)` — a bare whereIn
        // re-types the rest through `Query\Builder`'s `@mixin` (AGENTS.md).
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->with([
                'attachments:id,ticket_id,file_path,file_name,file_size,activity_id',
                // #1176: the 50-activity cap constrains the activities query;
                // its own eager loads ride nested dot-notation keys on the
                // same relation query. Chaining `->with()` inside this
                // closure trips phpstan — a Relation-typed param only has
                // `Query\Builder`'s methods via `@mixin`.
                'activities' => fn ($q) => $q->latest('id')->limit(self::ACTIVITY_HISTORY_LIMIT),
                'activities.attachments',
                'activities.user' => fn ($q) => ActorLabel::eagerLoadActor($q),
                'user' => fn ($q) => ActorLabel::eagerLoadActor($q),
                'unit',
            ])
            ->where(fn ($q) => $q->whereIn('unit_id', $accessibleIds))
            ->find($this->showingTicketId);

        if (! $ticket) {
            return null;
        }

        // #847: the ticket is scoped, but its `task` relation was not — so a
        // ticket linked to an out-of-scope todo leaked that todo's title,
        // dates and status here. Legacy rows predating validation still
        // exist, so the read side is gated independently of the write side.
        // A null-unit task is in scope for its own creator (#838 contract).
        //
        // `setRelation()`, not `$ticket->task = …`: a plain assignment would go
        // through `setAttribute()` and park the Todo in `$attributes`, from
        // where any later `save()` tries to write a non-existent `task`
        // column. The `@var` above is what lets PHPStan see the model here —
        // without it the chain resolves to `Query\Builder` via `@mixin`.
        $ticket->setRelation('task', $this->taskIfInScope($ticket->task_id, $accessibleIds));

        return $ticket;
    }

    /**
     * The permission the ticket mutations that move or close a ticket need
     * (#1055, step 3).
     *
     * Four call sites wrote `$this->authorize('manage_unit_tickets')` inline,
     * so the gate could be forgotten by the next method that mutates a ticket.
     * One named helper makes the shared gate explicit and greppable; the bulk
     * `complete` branch in the component routes through it too.
     *
     * `acceptTicket()` deliberately does NOT route through it (#1029): accepting
     * does not move the ticket out of its unit — it only hands it to its current
     * assignee — so it carries `create_ticket`, exactly like the API
     * (`POST /tickets/{ticket}/accept`, routes/api.php:153-154). The `expert`
     * role holds `create_ticket` but no `manage_unit_tickets`, and is meant to
     * accept tickets referred to it
     * (`test_accept_ticket_still_allowed_for_expert_without_manage_unit_tickets`).
     */
    private function authorizeMutation(): void
    {
        $this->authorize('manage_unit_tickets');
    }

    /**
     * Issue #818: one validation shape for the destination unit, shared by
     * `forward()` and `submitAction()`.
     *
     * `submitAction()` had no `targetUnitId` rule at all — the branch it
     * chose, the `unit_id` it wrote and the notification it fanned out all came
     * straight from a client property, and the FK's ON DELETE RESTRICT turned a
     * bogus id into a generic error toast instead of a validation message.
     *
     * The target is NOT constrained to `accessibleUnitIds()`: forwarding across
     * units is legitimate. Permission + eligibility is the contract.
     *
     * @return array<int, ValidationRule|string>
     */
    protected function targetUnitRule(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            new TicketTargetUnit,
        ];
    }

    public function showTicket($id): void
    {
        // Same access gate as before #1176 — an out-of-scope id stays a
        // silent no-op and the modal never opens. This lookup is the GATE;
        // the capped, relation-bearing load itself happens in the
        // `showingTicket` computed below, so no eager load happens here,
        // only the scope decision.
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $inScope = Ticket::query()
            ->whereKey($id)
            ->where(fn ($q) => $q->whereIn('unit_id', $accessibleIds))
            ->exists();

        if (! $inScope) {
            return;
        }

        $this->showingTicketId = (int) $id;
        $this->showModal = true;
    }

    /**
     * The ticket's task, or null when it is outside the viewer's scope.
     *
     * Returns the row with a null relation when it is not visible, so every
     * `@if($this->showingTicket->task)` block in the detail modal simply
     * hides itself instead of rendering a foreign todo.
     *
     * @param  array<int>  $accessibleIds
     */
    private function taskIfInScope(?int $taskId, array $accessibleIds): ?Todo
    {
        if (! $taskId) {
            return null;
        }

        return Todo::query()
            ->whereKey($taskId)
            ->where(fn ($q) => $q->whereIn('unit_id', $accessibleIds)
                ->orWhere(fn ($q) => $q->whereNull('unit_id')->where('user_id', Auth::id())))
            ->first();
    }

    public function closeDetail(): void
    {
        $this->showingTicketId = null;
        // The computed cache holds the just-displayed row for the rest of this
        // request; dropping it keeps the id and the re-render in lockstep.
        unset($this->showingTicket);
        $this->reset(['targetUnitId', 'targetUnitName', 'unitSearch', 'forwardNote']);
        $this->showModal = false;
    }

    public function selectTargetUnit($id): void
    {
        // Issue #818: the name is resolved here, never taken from the client —
        // it is written verbatim into activity descriptions and notifications,
        // so a client-supplied name was attacker-controlled audit-trail text.
        // The eligibility check still happens in forward()/submitAction(); an
        // unknown id simply resolves to an empty name here.
        $this->targetUnitId = $id === null ? null : (int) $id;
        $this->targetUnitName = (string) (Unit::query()->whereKey($this->targetUnitId)->value('name') ?? '');
        $this->unitSearch = '';
    }

    public function forward(): void
    {
        // Issue #818: /tickets/inbox is gated only on view_assigned_tickets
        // (which `expert` holds), so without this the web path could move a
        // ticket to any unit while the API required manage_unit_tickets for
        // the same operation. Match the API (routes/api.php:110-119).
        $this->authorizeMutation();

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // #1176: the actionable ticket is re-derived here from the id instead
        // of reading the computed model — the computed serves the VIEW; a
        // write path rules on its own scoped lookup (#1055: same
        // UNCONDITIONAL `whereIn`, compiled fail-closed), on current data.
        $ticket = Ticket::query()
            ->where(fn ($q) => $q->whereIn('unit_id', $accessibleIds))
            ->find($this->showingTicketId);

        if (! $ticket) {
            $this->dispatch('swal', ['title' => 'شما مجاز به ارجاع این تیکت نیستید.', 'icon' => 'error']);

            return;
        }

        $this->validate([
            'targetUnitId' => $this->targetUnitRule(required: true),
            'forwardNote' => 'nullable|string|max:500',
        ]);

        // Re-resolve server-side: targetUnitName is display state the client can
        // still set, and it lands in the audit trail.
        $targetUnitName = $this->resolveTargetUnitName();

        try {
            DB::transaction(function () use ($ticket, $targetUnitName) {
                $ticket->update([
                    'unit_id' => $this->targetUnitId,
                    'status' => 'forwarded',
                    'current_assignee_id' => null,
                ]);

                $ticket->activities()->create([
                    'user_id' => auth()->id(),
                    'action' => 'forwarded',
                    'description' => 'ارجاع تیکت به واحد: '.$targetUnitName.' - توضیحات: '.$this->forwardNote,
                    // Issue #818: the destination was only in the description;
                    // nothing recorded it structurally on this path.
                    'to_unit_id' => $this->targetUnitId,
                ]);

                // Issue #818 (correction 5): forward() sent no notification while
                // submitAction() did, so two ways of doing the same thing behaved
                // differently. Both paths now notify the destination unit, and both
                // do it inside the transaction — so a failed fan-out rolls the
                // move back instead of leaving a ticket forwarded silently.
                NotificationService::notifyUnit(
                    $this->targetUnitId,
                    'ticket_forwarded',
                    'تیکت ارجاع شد',
                    "تیکت #{$ticket->ticket_code} به واحد شما ارجاع شد - موضوع: {$ticket->subject}",
                    '/tickets/inbox'
                );
            });

            $this->dispatch('swal', ['title' => 'تیکت با موفقیت ارجاع شد', 'icon' => 'success']);
            $this->closeDetail();
        } catch (\Exception $e) {
            $this->dispatch('swal', ['title' => 'خطا در انجام عملیات', 'icon' => 'error']);
        }
    }

    /**
     * Issue #818: the unit name is display state that travels through the
     * request, so it is re-read from the database before it is written into an
     * activity description or a notification body.
     */
    protected function resolveTargetUnitName(): string
    {
        $name = $this->targetUnitId
            ? Unit::query()->whereKey($this->targetUnitId)->value('name')
            : null;

        return (string) ($name ?? '');
    }

    public function acceptTicket($ticketId): void
    {
        // Issue #1029: accepting is a `create_ticket` mutation, mirroring the
        // API (`POST /tickets/{ticket}/accept`, routes/api.php:153-154) — not
        // `manage_unit_tickets`, which the `expert` role deliberately lacks.
        // The /inbox route only requires `view_assigned_tickets`, so the
        // component authorizes the write itself, before any query or change.
        $this->authorize('create_ticket');

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);

        if (! $ticket) {
            return;
        }

        // Issue #1029 / #529: only the ticket's current assignee may accept it,
        // exactly like Api\TicketController::accept(). Without this any member
        // of the unit could accept — and thereby seize — a ticket referred to a
        // teammate.
        if ($ticket->current_assignee_id !== Auth::id()) {
            $this->dispatch('swal', [
                'title' => 'فقط کارشناس ارجاع‌شده می‌تواند این تیکت را بپذیرد.',
                'icon' => 'error',
            ]);

            return;
        }

        // Capture old status BEFORE any changes (Plan 003: dynamic, not hardcoded)
        $oldStatus = $ticket->status;

        // Plan 002: Only allow accepting from 'created' or 'forwarded' status
        if ($oldStatus !== 'created' && $oldStatus !== 'forwarded') {
            $this->dispatch('swal', [
                'title' => 'تیکت قبلاً پذیرفته شده است',
                'icon' => 'warning',
            ]);

            return;
        }

        DB::transaction(function () use ($ticket, $oldStatus) {
            // Plan 002: Pessimistic lock to prevent concurrent accept
            $locked = DB::select('SELECT id, status FROM tickets WHERE id = ? FOR UPDATE', [$ticket->id]);

            if ($locked[0]->status !== $oldStatus) {
                $this->dispatch('swal', [
                    'title' => 'تیکت قبلاً پذیرفته شده است',
                    'icon' => 'warning',
                ]);

                return;
            }

            $ticket->update([
                'status' => 'accepted',
                'current_assignee_id' => auth()->id(),
                'accepted_at' => now(),
            ]);

            $ticket->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'accepted',
                'description' => 'تیکت توسط کارشناس تایید شد و مسئولیت آن پذیرفته شد.',
            ]);

            // Plan 003: Use dynamic old status, not hardcoded 'created'
            ActivityLogService::updated(
                $ticket,
                ['status' => $oldStatus],
                ['status' => 'accepted'],
                "پذیرش تیکت {$ticket->ticket_code}"
            );
        });

        $this->dispatch('swal', ['title' => 'تیکت پذیرفته شد', 'icon' => 'success']);
        $this->closeDetail();
    }

    public function rejectTicket($ticketId): void
    {
        // Issue #818: no API counterpart exists, so manage_unit_tickets is the
        // right proxy — rejecting is the same class of state change as
        // completing, and the route gate alone never checked it.
        $this->authorizeMutation();

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($ticketId);

        if (! $ticket) {
            $this->dispatch('swal', ['title' => 'تیکت یافت نشد.', 'icon' => 'error']);

            return;
        }

        DB::transaction(function () use ($ticket) {
            $ticket->update([
                'status' => 'rejected',
                'current_assignee_id' => auth()->id(),
            ]);

            $ticket->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'rejected',
                'description' => 'تیکت توسط واحد '.(auth()->user()->person?->unit?->name ?? 'بدون واحد').' رد شد.',
            ]);
        });

        $this->dispatch('swal', ['title' => 'تیکت با موفقیت رد شد', 'icon' => 'info']);
        $this->closeDetail();
    }

    public function openCompletionModal($id): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $ticket = Ticket::whereIn('unit_id', $accessibleIds)->find($id);

        if (! $ticket) {
            $this->dispatch('swal', ['title' => 'شما مجاز به مشاهده این تیکت نیستید.', 'icon' => 'error']);

            return;
        }

        // The scoped find above is still the gate (#1055); the completion
        // modal reads its data from the scoped `showingTicket` computed, so
        // only the id is remembered here (#1176).
        $this->showingTicketId = (int) $id;
        $this->isCompletionModalOpen = true;
    }

    public function submitAction($id = null): void
    {
        // Issue #818: same gate as forward() — this path could complete a ticket
        // or move it to any unit in the system under view_assigned_tickets alone.
        $this->authorizeMutation();

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $finalId = $id ?? $this->showingTicketId;

        $ticket = Ticket::whereIn('unit_id', $accessibleIds)->findOrFail($finalId);

        if ($ticket->status !== 'accepted' && ! $this->targetUnitId) {
            $this->addError('completionNote', 'تیکت تایید نشده را نمی‌توان مختومه کرد. ابتدا ارجاع دهید یا تایید کنید.');

            return;
        }

        $actionType = $this->targetUnitId ? 'forwarded' : 'completed';

        $this->validate([
            // Issue #818: this rule did not exist here — only completionNote and
            // completionFiles were validated, while the branch, the unit_id and
            // the notification all came from targetUnitId.
            'targetUnitId' => $this->targetUnitRule(required: false),
            'completionNote' => $actionType === 'completed' ? 'required|min:5' : 'nullable|max:1000',
            'completionFiles' => 'nullable|array|max:5',
            'completionFiles.*' => 'file|mimes:jpg,jpeg,png,pdf,zip,rar,docx,xlsx|max:5120',
        ]);

        // Issue #818: never write the client-supplied name into the audit trail
        // or the notification body.
        $targetUnitName = $this->resolveTargetUnitName();

        try {
            DB::beginTransaction();

            if ($actionType === 'forwarded') {
                $ticket->update([
                    'unit_id' => $this->targetUnitId,
                    'status' => 'forwarded',
                    'current_assignee_id' => null,
                ]);
                $description = "ارجاع تیکت به واحد: {$targetUnitName}";
                if ($this->completionNote) {
                    $description .= " | توضیحات: {$this->completionNote}";
                }
                $message = "تیکت با موفقیت به واحد {$targetUnitName} ارجاع شد.";

                // ارسال اعلان به واحد مقصد
                NotificationService::notifyUnit(
                    $this->targetUnitId,
                    'ticket_forwarded',
                    'تیکت ارجاع شد',
                    "تیکت #{$ticket->ticket_code} به واحد شما ارجاع شد - موضوع: {$ticket->subject}",
                    '/tickets/inbox'
                );
            } else {
                $ticket->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                $description = "تیکت مختومه شد. گزارش نهایی: {$this->completionNote}";
                $message = 'تیکت با موفقیت مختومه و بسته شد.';

                // اگر وظیفه مرتبطی دارد و تمام تیکت‌های آن وظیفه بسته شده‌اند، وظیفه را تکمیل کن
                if ($ticket->task_id) {
                    $relatedTicket = Ticket::where('task_id', $ticket->task_id)
                        ->where('status', '!=', 'completed')
                        ->where('id', '!=', $ticket->id)
                        ->count();
                    if ($relatedTicket === 0) {
                        $ticket->task->update(['is_completed' => true]);
                        $message .= ' وظیفه مرتبط نیز تکمیل شد.';
                    }
                }
            }

            $newActivity = $ticket->activities()->create([
                'user_id' => auth()->id(),
                'action' => $actionType,
                'description' => $description,
                'to_unit_id' => $this->targetUnitId ?? $ticket->unit_id,
            ]);

            if ($this->completionFiles) {
                foreach ($this->completionFiles as $file) {
                    $path = $file->store('attachments', 'local');
                    $ticket->attachments()->create([
                        'user_id' => auth()->id(),
                        'activity_id' => $newActivity->id,
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            DB::commit();

            // #1176: `showingTicket` became a computed — resetting it meant
            // writing to a computed and blowing up. The id reset covers the
            // state; the unset drops the stale per-request model cache so any
            // read in the same request re-derives (and finds nothing).
            $this->reset(['isCompletionModalOpen', 'showingTicketId', 'completionNote', 'completionFiles', 'targetUnitId', 'targetUnitName', 'unitSearch']);
            unset($this->showingTicket);

            $this->dispatch('swal', [
                'title' => 'عملیات موفق',
                'text' => $message,
                'icon' => 'success',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->addError('completionNote', 'خطایی در ثبت عملیات رخ داد: '.$e->getMessage());
        }
    }

    public function removeFile($index): void
    {
        array_splice($this->completionFiles, $index, 1);
    }
}
