<?php

use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Rules\TicketTargetUnit;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use App\Support\JalaliDateRange;
use App\Traits\PersianNormalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use \App\Traits\TicketsInboxFlow;
    // Fully qualified: a top-of-file import would shift the anonymous class's
    // identity line and break every line-keyed PHPStan-baseline entry for it.
    // The top `JalaliDateRange` import is now unused (this trait owns the date
    // parse) but stays in place so the identity line keeps its beta position.
    use \App\Traits\TicketsQueryScopes;
    use PersianNormalizer;

    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $unitSearch = '';

    // The single-ticket flow's state (the ticket being shown, the target unit,
    // the completion modal and its attachments) lives in TicketsInboxFlow below.
    #[Url]
    public string $forwardNote = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $currentTab = 'pending';

    #[Url]
    public string $viewMode = 'received';

    #[Url]
    public string $statusFilter = 'pending';

    // Bulk Actions
    public array $selectedTickets = [];

    public bool $selectAll = false;

    public bool $showBulkModal = false;

    public string $bulkAction = '';

    public string $bulkNote = '';

    // #1690: dedicated bulk-modal destination state. It is deliberately NOT
    // the single-ticket flow's `targetUnitId`: `openCompletionModal()` never
    // resets that property (and the cancel button only flips the boolean), so
    // sharing it would leak a cancelled single-forward destination into the
    // bulk modal and vice versa.
    public ?int $bulkTargetUnitId = null;

    public string $bulkTargetUnitName = '';

    public string $bulkUnitSearch = '';

    // Data properties
    public array $units = [];

    public bool $showHelpModal = false;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $user = auth()->user();
        $units = [];

        if (mb_strlen($this->unitSearch) > 1) {
            // Issue #818: same eligibility as App\Rules\TicketTargetUnit —
            // a hidden filter here was never re-checked server-side.
            $units = Unit::where('name', 'like', '%'.self::escapeLikeWildcards($this->unitSearch).'%')
                ->where('can_receive_tickets', true)
                ->where('is_active', true)
                ->where('id', '!=', auth()->user()->person?->u_id)
                ->limit(5)
                ->get()
                ->toArray();
        }

        $this->units = $units;
    }

    #[Computed]
    public function tickets()
    {
        // #906: `deleted_at` is selected on purpose — `ActorLabel` tells a
        // soft-deleted creator («کاربر غیرفعال») from a genuinely ownerless
        // ticket («سیستم»), and `trashed()` reads that column. Without the
        // soft-deleted rows in the result both cases resolve to `null` and are
        // indistinguishable.
        $query = Ticket::with(['user' => fn ($q) => \App\Support\ActorLabel::eagerLoadActor($q), 'unit:id,name']);

        $this->applyInboxFilters($query);

        return $query->latest()->paginate(20);
    }

    /**
     * #1690: the bulk forward destination picker. Same eligibility filter as
     * `loadData()` (and therefore `App\Rules\TicketTargetUnit`), scoped to the
     * bulk modal's own search term.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function bulkUnits(): array
    {
        if (mb_strlen($this->bulkUnitSearch) <= 1) {
            return [];
        }

        return Unit::where('name', 'like', '%'.self::escapeLikeWildcards($this->bulkUnitSearch).'%')
            ->where('can_receive_tickets', true)
            ->where('is_active', true)
            ->where('id', '!=', auth()->user()->person?->u_id)
            ->limit(5)
            ->get()
            ->toArray();
    }

    /**
     * The filter predicate the inbox table itself runs — viewMode scope
     * (received = `accessible()`, sent = owner/assignee/activity paths), tab
     * status filter, Jalali date window and search — appended onto whichever
     * base query the caller builds. Extracted unchanged from `tickets()` so
     * #1040's select-all expansion and count see EXACTLY the rows the
     * operator sees, instead of a copy that could drift.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Ticket>  $query
     */
    private function applyInboxFilters(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $user = auth()->user();

        if ($this->viewMode === 'received') {
            $query->accessible();
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('current_assignee_id', $user->id)
                    ->orWhereHas('activities', fn ($q) => $q->where('user_id', $user->id));
            });
        }

        $this->applyTicketStatusFilter($query, $this->statusFilter);
        $this->applyTicketDateRange($query, $this->dateFrom, $this->dateTo);
        // The inbox searches `content` as well; monitoring does not.
        $this->applyTicketSearch($query, $this->search, ['subject', 'ticket_code', 'content']);
    }

    public function updatedViewMode(): void
    {
        $this->resetPage();
        $this->loadData();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->loadData();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->loadData();
    }

    public function updatedUnitSearch(): void
    {
        $this->loadData();
    }

    public function updateFilter($viewMode, $statusFilter = 'pending'): void
    {
        $this->viewMode = $viewMode;
        $this->statusFilter = $statusFilter;
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
        $this->loadData();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
        $this->loadData();
    }

    public function setTab($tab): void
    {
        $this->currentTab = $tab;
        $this->resetPage();
    }

    public function closeAllModals(): void
    {
        $this->isCompletionModalOpen = false;
        // #1176: `showingTicket` is a computed now — only the id is state,
        // and the unset drops the per-request model cache beside it.
        $this->showingTicketId = null;
        unset($this->showingTicket);
        $this->reset([
            'completionNote', 'completionFiles', 'targetUnitId',
            'targetUnitName', 'unitSearch', 'selectedTickets',
            'selectAll', 'showBulkModal', 'bulkAction', 'bulkNote',
            'bulkTargetUnitId', 'bulkTargetUnitName', 'bulkUnitSearch',
        ]);
    }

    // ===== Bulk Actions =====
    public function toggleTicketSelection($ticketId): void
    {
        // Under `$selectAll = false` this toggles a MANUAL pick (inclusion);
        // under `$selectAll = true` the same call marks the row OUT of the
        // all-selection (exclusion — the checked renderer and the count both
        // read it that way). One action, two sides of the same ledger, both
        // pinned by tests.
        if (in_array($ticketId, $this->selectedTickets)) {
            $this->selectedTickets = array_values(array_diff($this->selectedTickets, [$ticketId]));
        } else {
            $this->selectedTickets[] = $ticketId;
        }
    }

    /**
     * #1040: `$selectAll` is the source of truth and nothing is materialized
     * into `selectedTickets` here — the old code plucked EVERY open ticket
     * in the accessible scope into the public array and shipped it across
     * every roundtrip. Expansion happens at action time on the server
     * (`executeBulkAction()`), against the page's active filter/search; the
     * ledger stays empty the whole time.
     */
    public function toggleSelectAll(): void
    {
        $this->selectAll = ! $this->selectAll;
        $this->selectedTickets = [];
    }

    /**
     * #1040: how many rows the operator believes are selected. Without
     * select-all that is the manual picks; with it, a server-side COUNT of
     * the page's filtered set minus the manually UN-picked rows. The bulk
     * bar and both its buttons key off this instead of the snapshot array's
     * length.
     */
    #[Computed]
    public function selectedTicketsCount(): int
    {
        if (! $this->selectAll) {
            return count($this->selectedTickets);
        }

        // #1408: the aggregate is memoised under `report_tickets`, whose
        // version is bumped by the Ticket model hooks (AppServiceProvider) and
        // by this component's bulk writes. The 5-minute TTL is only a backstop
        // for a future write path that bypasses both — it does not carry
        // correctness today.
        $unpicked = array_map('intval', $this->selectedTickets);
        sort($unpicked);

        return (int) app(CacheInvalidationServiceInterface::class)->remember(
            'report_tickets',
            \App\Support\ScopeCacheKey::hash(app(AccessService::class)->accessibleUnitIds()),
            function (): int {
                $query = Ticket::query();
                $this->applyInboxFilters($query);

                return $query->whereNotIn('id', $this->selectedTickets)->count();
            },
            5,
            [
                'uid' => (int) Auth::id(),
                'view' => $this->viewMode,
                'status' => $this->statusFilter,
                'from' => $this->dateFrom,
                'to' => $this->dateTo,
                'q' => $this->search,
                'unpicked' => $unpicked,
            ],
        );
    }

    /**
     * #1040: today's client-only `$set('selectedTickets', [])` left the
     * `selectAll=true` flag standing with an empty ledger — bulk actions
     * silently found nothing while every row still rendered checked. Clear
     * BOTH flags.
     */
    public function clearSelection(): void
    {
        $this->reset(['selectedTickets', 'selectAll']);
    }

    public function openBulkModal($action): void
    {
        // #1690: an unknown action must not reach the modal — the confirm
        // button would otherwise call executeBulkAction() with a value the
        // server has to reject anyway.
        if (! in_array($action, ['complete', 'forward'], true)) {
            $this->dispatch('swal', ['title' => 'عملیات دسته‌ای نامعتبر است', 'icon' => 'warning']);

            return;
        }

        if ($this->selectedTicketsCount() === 0) {
            $this->dispatch('swal', ['title' => 'هیچ تیکتی انتخاب نشده', 'icon' => 'warning']);

            return;
        }

        // #1690: start every open from a clean bulk state — a destination
        // picked in a previous bulk forward must not survive into the next one.
        $this->reset(['bulkNote', 'bulkTargetUnitId', 'bulkTargetUnitName', 'bulkUnitSearch']);
        unset($this->bulkUnits);
        $this->isCompletionModalOpen = false;
        $this->bulkAction = $action;
        $this->showBulkModal = true;
    }

    /**
     * #1690: the bulk modal's own cancel. It resets only the bulk-modal state
     * and keeps `selectedTickets`/`selectAll` — routing this through
     * `closeAllModals()` would wipe the operator's selection on a mere cancel.
     */
    public function closeBulkModal(): void
    {
        $this->reset([
            'showBulkModal', 'bulkAction', 'bulkNote',
            'bulkTargetUnitId', 'bulkTargetUnitName', 'bulkUnitSearch',
        ]);
        unset($this->bulkUnits);
    }

    public function selectBulkTargetUnit(?int $id): void
    {
        // #1690: server-resolved name (never the client's), mirroring the
        // single-ticket `selectTargetUnit()` — the name is audit/notification
        // text.
        $this->bulkTargetUnitId = $id === null ? null : (int) $id;
        $this->bulkTargetUnitName = (string) (Unit::query()->whereKey($this->bulkTargetUnitId)->value('name') ?? '');
        $this->bulkUnitSearch = '';
        unset($this->bulkUnits);
    }

    public function executeBulkAction(): void
    {
        // #1690: reject an unknown action FIRST — before the selection count,
        // the permission check and any query. The old code treated it as an
        // un-gated no-op that still dispatched a success toast.
        if (! in_array($this->bulkAction, ['complete', 'forward'], true)) {
            $this->dispatch('swal', ['title' => 'عملیات دسته‌ای نامعتبر است', 'icon' => 'warning']);

            return;
        }

        // #1040: the effective selection (select-all seen through the page's
        // filter, minus manual un-picks, or the manual picks alone) has to be
        // resolved BEFORE any state reset — this method is also the only
        // place that materializes it.
        if ($this->selectedTicketsCount() === 0) {
            return;
        }

        // Issue #818 / #1624 / #1690: both branches mutate tickets in bulk
        // (`complete` closes them; `forward` moves them to another unit and
        // unassigns them) — the same state changes the single-ticket paths
        // gate on `manage_unit_tickets`. The UI hides both buttons behind
        // `@can('manage_unit_tickets')`, but that is presentation only:
        // Livewire mutators are reachable via `POST /livewire/update`, where
        // the route gate does not run. Authorizing BEFORE destination
        // validation keeps an expert at 403 even when no destination is set.
        $this->authorizeMutation();

        // #1690: destination is mandatory for a real bulk forward (parity with
        // the single-ticket `forward()`); `complete` ignores it explicitly.
        $this->validate([
            'bulkNote' => 'nullable|string|max:500',
            'bulkTargetUnitId' => $this->bulkAction === 'forward'
                ? $this->targetUnitRule(required: true)
                : 'nullable',
        ]);

        $destinationId = $this->bulkAction === 'forward' ? (int) $this->bulkTargetUnitId : null;
        // #818: the client-supplied `bulkTargetUnitName` is display state only;
        // the name written to audit/notification text is re-read from the DB.
        $destinationName = $destinationId
            ? (string) Unit::query()->whereKey($destinationId)->value('name')
            : '';

        $now = now();
        $userId = auth()->id();
        $bulkNote = $this->bulkNote;

        // Get accessible unit IDs for scope filtering
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // #1620: resolution AND the writes stay in ONE transaction. The
        // terminal-state definition the single-ticket paths already share
        // (`TicketsInboxFlow::isClosedTicketRow()`) counts `completed`,
        // `rejected` AND a non-null `archived_at` as closed; the bulk filter
        // mirrors it so a rejected ticket can never be completed/forwarded in
        // bulk. `lockForUpdate()` closes the check-then-act window a
        // concurrent single-ticket transition would otherwise open between
        // resolution and the set-based UPDATE.
        [$ticketIds, $skippedCount] = DB::transaction(function () use ($accessibleIds, $now, $userId, $bulkNote, $destinationId, $destinationName) {
            // ۱. یک کوئری برای همه تیکت‌ها - فیلتر تیکت‌های پایانی (completed/rejected/آرشیوشده) + محدوده سازمانی
            //
            // #1040: «انتخاب همه» is NOT the whole accessible pool any more —
            // the approved semantics expand it right here, against the page's
            // ACTIVE filter/search/view-mode, minus the rows the operator
            // un-picked. The manual path is unchanged (`selectedTickets` holds
            // only hand-picked ids in that mode).
            $candidatesQuery = Ticket::query();

            if ($this->selectAll) {
                $this->applyInboxFilters($candidatesQuery);
                $candidatesQuery->whereNotIn('id', $this->selectedTickets);
            } else {
                $candidatesQuery->whereIn('id', $this->selectedTickets);
            }

            $candidates = $candidatesQuery
                ->whereNotIn('status', ['completed', 'rejected'])
                ->whereNull('archived_at')
                ->whereIn('unit_id', $accessibleIds)
                ->lockForUpdate()
                ->get(['id', 'status', 'current_assignee_id']);

            // #1686: bulk `complete` mirrors the single-ticket completion rule —
            // `TicketsInboxFlow::submitAction()` (:655-672) requires the LOCKED
            // row to be `accepted` and its `current_assignee_id` to be the actor
            // (#1609: only the ticket's current assignee may close it, even when
            // the caller holds `manage_unit_tickets`; parity with
            // `Api\TicketController::complete()`). The decision is taken on the
            // locked rows rather than in SQL so the check-then-act window stays
            // closed and the skipped count needs no second query. `forward`
            // keeps its own (unrestricted) rules.
            $skippedCount = 0;

            if ($this->bulkAction === 'complete') {
                [$eligible, $skipped] = $candidates->partition(
                    fn ($row) => $row->status === 'accepted'
                        && (int) $row->current_assignee_id === (int) $userId
                );
                $skippedCount = $skipped->count();
            } else {
                $eligible = $candidates;
            }

            $ticketIds = $eligible->pluck('id');

            if ($ticketIds->isEmpty()) {
                return [$ticketIds, $skippedCount];
            }

            // Issue #531: capture old statuses BEFORE the bulk update
            // #1409: one combined pre-read replaces the old-status pluck AND the
            // per-branch re-fetch; ticket_code/task_id feed the batched log insert.
            $ticketExtras = Ticket::whereIn('id', $ticketIds)
                ->get(['id', 'status', 'ticket_code', 'task_id', 'unit_id'])
                ->keyBy('id');
            $oldStatuses = $ticketExtras->pluck('status', 'id');
            $ticketCodeMap = $ticketExtras->pluck('ticket_code', 'id');
            $taskIdMap = $ticketExtras->pluck('task_id');
            $unitIdMap = $ticketExtras->pluck('unit_id', 'id');

            if ($this->bulkAction === 'complete') {
                // ۲. یک UPDATE برای همه
                Ticket::whereIn('id', $ticketIds)->update([
                    'status' => 'completed',
                    'completed_at' => $now,
                ]);
                // Issue #378/#389: bulk update bypasses Eloquent events — bump caches manually
                $cache = app(CacheInvalidationServiceInterface::class);
                $cache->increment('report_tickets');
                $cache->increment('gis');
                $cache->increment('calendar');
                $cache->increment('dashboard');

                // ۳. یک batch INSERT برای فعالیت‌ها
                $activityRows = $ticketIds->map(fn ($id) => [
                    'ticket_id' => $id,
                    'user_id' => $userId,
                    'action' => 'completed',
                    'description' => 'تکمیل دسته‌ای تیکت'.($bulkNote ? ": {$bulkNote}" : ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();
                TaskActivity::insert($activityRows);

                // #1409: one batched INSERT instead of one per ticket
                $activityLogRows = $ticketIds->map(function ($id) use ($userId, $now, $oldStatuses, $ticketCodeMap) {
                    return [
                        'user_id' => $userId,
                        'type' => 'updated',
                        'subject_type' => 'App\Models\Ticket',
                        'subject_id' => $id,
                        'description' => 'تکمیل دسته‌ای تیکت '.($ticketCodeMap->get($id) ?? ''),
                        'old_values' => json_encode(['status' => $oldStatuses->get($id)]),
                        'new_values' => json_encode(['status' => 'completed']),
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->toArray();

                \App\Models\ActivityLog::query()->insert($activityLogRows);

                $completedTaskIds = $taskIdMap->filter()->unique();

                // Plan 005: Auto-complete parent tasks where all child tickets are completed
                // #1728: the sibling check uses the shared OPEN predicate, so a
                // rejected (terminal) sibling no longer blocks completion.
                //
                // One grouped count + one mass update (was: one count + one update
                // per linked todo, inside this transaction). Invariant: a todo with
                // zero open tickets has NO row in $openCounts — a missing key means
                // "all closed", never "unknown". Do not rewrite this as a LEFT JOIN
                // against todos without preserving that, or a partially-completed
                // todo gets marked done.
                $openCounts = Ticket::query()
                    ->whereIn('task_id', $completedTaskIds->all())
                    ->open()
                    ->selectRaw('task_id, count(*) as open_count')
                    ->groupBy('task_id')
                    ->pluck('open_count', 'task_id');

                $doneTaskIds = $completedTaskIds
                    ->reject(fn ($taskId) => $openCounts->has($taskId))
                    ->values();

                if ($doneTaskIds->isNotEmpty()) {
                    Todo::query()->whereIn('id', $doneTaskIds->all())->update(['is_completed' => true]);

                    // #1934: the mass update bypasses `Todo::updated` — bump
                    // `report_todos` manually. `dashboard` was already bumped at
                    // :512 for the ticket mass-update.
                    $cache->increment('report_todos');
                }
            }

            if ($this->bulkAction === 'forward') {
                // #1690: a bulk forward now actually MOVES the ticket, exactly
                // like `TicketsInboxFlow::forward()`: `unit_id` changes, the
                // ticket becomes `forwarded` (part of the `pending` tab) and
                // loses its assignee, so it appears in the destination unit.
                Ticket::whereIn('id', $ticketIds)->update([
                    'unit_id' => $destinationId,
                    'status' => 'forwarded',
                    'current_assignee_id' => null,
                ]);
                // Issue #378/#389: bulk update bypasses Eloquent events — bump caches manually
                $cache = app(CacheInvalidationServiceInterface::class);
                $cache->increment('report_tickets');
                $cache->increment('gis');
                $cache->increment('calendar');
                $cache->increment('dashboard');

                $activityRows = $ticketIds->map(fn ($id) => [
                    'ticket_id' => $id,
                    'user_id' => $userId,
                    'action' => 'forwarded',
                    'to_unit_id' => $destinationId,
                    'description' => "ارجاع دسته‌ای تیکت به واحد: {$destinationName}".($bulkNote ? " - توضیحات: {$bulkNote}" : ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();
                TaskActivity::insert($activityRows);

                // #1409: one batched INSERT instead of one per ticket
                $activityLogRows = $ticketIds->map(function ($id) use ($userId, $now, $oldStatuses, $ticketCodeMap, $unitIdMap, $destinationId) {
                    return [
                        'user_id' => $userId,
                        'type' => 'updated',
                        'subject_type' => 'App\Models\Ticket',
                        'subject_id' => $id,
                        'description' => 'ارجاع دسته‌ای تیکت '.($ticketCodeMap->get($id) ?? ''),
                        'old_values' => json_encode(['status' => $oldStatuses->get($id), 'unit_id' => $unitIdMap->get($id)]),
                        'new_values' => json_encode(['status' => 'forwarded', 'unit_id' => $destinationId]),
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->toArray();

                \App\Models\ActivityLog::query()->insert($activityLogRows);

                // #1690: ONE notification per destination unit (not per
                // ticket), inside the same transaction as the move — parity
                // with the single-ticket path. `notifyUnit` early-returns for
                // a unit with no users; a throw rolls the move back.
                \App\Services\NotificationService::notifyUnit(
                    (int) $destinationId,
                    'ticket_forwarded',
                    'تیکت ارجاع شد',
                    "{$ticketIds->count()} تیکت به واحد شما ارجاع شد",
                    '/tickets/inbox'
                );
            }

            return [$ticketIds, $skippedCount];
        });

        if ($ticketIds->isEmpty()) {
            $this->dispatch('swal', [
                'title' => $skippedCount > 0
                    ? "{$skippedCount} تیکت رد شد: فقط تیکت‌های تأییدشده و ارجاع‌شده به شما قابل تکمیل هستند"
                    : 'هیچ تیکت قابل پردازشی یافت نشد',
                'icon' => 'warning',
            ]);
            $this->closeAllModals();

            return;
        }

        // #1001: report the tickets actually processed after scope/status
        // filtering — not how many the operator selected. #1686: a bulk
        // `complete` that closed nothing (or only some) now says how many
        // candidates were refused by the accepted/assignee rule.
        $title = "{$ticketIds->count()} تیکت با موفقیت پردازش شد";
        if ($skippedCount > 0) {
            $title .= "؛ {$skippedCount} تیکت رد شد (تأییدنشده یا ارجاع‌شده به کارشناس دیگر)";
        }

        $this->dispatch('swal', ['title' => $title, 'icon' => 'success']);
        $this->closeAllModals();
        $this->resetPage();
    }

    // متدهای bulkCompleteTicket و bulkForwardTicket حذف شدند - منطق در executeBulkAction ادغام شد
    // (#1040: getAccessibleUnitIds هم حذف شد — تنها فراخواننده‌اش مادی‌سازیِ
    // «انتخاب همه» در toggleSelectAll بود.)

    public function switchView($mode): void
    {
        $this->viewMode = $mode;
        $this->statusFilter = 'pending';
        $this->resetPage();
    }

    public function openCommentsFor($ticketId): void
    {
        $this->dispatch('openComments', ticketId: (int) $ticketId);
    }
};
?>

<div>
    <x-header title="صندوق تیکت‌های پشتیبانی" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <div class="flex gap-2">
                <x-button
                    wire:click="updateFilter('received', 'pending')"
                    label="ورودی‌ها"
                    icon="o-inbox"
                    class="{{ $this->viewMode === 'received' ? 'btn-primary' : 'btn-ghost' }}" />
                <x-button
                    wire:click="updateFilter('sent', 'pending')"
                    label="ارسالی‌ها"
                    icon="o-paper-airplane"
                    class="{{ $this->viewMode === 'sent' ? 'btn-primary' : 'btn-ghost' }}" />
            </div>
        </x-slot:middle>
        <x-slot:actions>
            <x-help:button section="tickets" wireModel="showHelpModal" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" section="tickets" />

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <div class="flex gap-2">
                <x-button
                    wire:click="$set('statusFilter', 'all')"
                    label="همه"
                    class="btn-xs {{ $this->statusFilter === 'all' ? 'btn-neutral' : 'btn-outline' }}" />
                <x-button
                    wire:click="$set('statusFilter', 'pending')"
                    label="در انتظار"
                    class="btn-xs {{ $this->statusFilter === 'pending' ? 'btn-warning' : 'btn-outline' }}" />
                <x-button
                    wire:click="$set('statusFilter', 'accepted')"
                    label="انجام"
                    class="btn-xs {{ $this->statusFilter === 'accepted' ? 'btn-info' : 'btn-outline' }}" />
                <x-button
                    wire:click="$set('statusFilter', 'completed')"
                    label="تکمیل"
                    class="btn-xs {{ $this->statusFilter === 'completed' ? 'btn-success' : 'btn-outline' }}" />
            </div>
            <div class="flex-1">
                <x-input
                    placeholder="جستجوی کد یا موضوع..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full" />
            </div>
            <div class="flex gap-2" wire:ignore>
                <input data-jdp id="filter_date_from" placeholder="از تاریخ"
                    class="input input-bordered input-sm w-28 text-center cursor-pointer" readonly>
                <input data-jdp id="filter_date_to" placeholder="تا تاریخ"
                    class="input input-bordered input-sm w-28 text-center cursor-pointer" readonly>
            </div>
        </div>

        {{-- نوار Bulk Actions — Issue #818: تکمیل دسته‌ای نیاز به manage_unit_tickets دارد --}}
        {{-- #1040: شمارنده و شرط نمایش از count سمت سرور می‌آیند؛ زیر «انتخاب همه»،
             selectedTickets فقط منهای دستی سطرها را حمل می‌کند و به‌تنهایی طول نداشته معنا ندارد. --}}
        @if($this->selectedTicketsCount > 0)
        <div class="flex items-center gap-3 p-3 mb-4 bg-primary/10 rounded-xl border border-primary/20">
            <span class="text-sm font-bold text-primary">{{ $this->selectedTicketsCount }} تیکت انتخاب شده</span>
            <div class="flex gap-2 mr-auto">
                @can('manage_unit_tickets')
                <x-button icon="o-check-circle" label="تکمیل دسته‌ای" wire:click="openBulkModal('complete')" class="btn-success btn-sm" spinner />
                <x-button icon="o-arrow-right" label="ارجاع دسته‌ای" wire:click="openBulkModal('forward')" class="btn-warning btn-sm" spinner />
                @endcan
                <x-button icon="o-x-mark" label="لغو انتخاب" wire:click="clearSelection" class="btn-ghost btn-sm" />
            </div>
        </div>
        @endif

        <x-table :headers="[
            ['key' => 'checkbox', 'label' => '', 'class' => 'w-10', 'sortable' => false],
            ['key' => 'user.person.f_name', 'label' => 'ایجاد کننده', 'class' => 'w-40'],
            ['key' => 'priority', 'label' => 'اولویت', 'class' => 'hidden md:table-cell text-center'],
            ['key' => 'status_name', 'label' => 'وضعیت', 'class' => 'hidden md:table-cell text-center'],
            ['key' => 'subject', 'label' => 'موضوع'],
            ['key' => 'unit.name', 'label' => 'نزد واحد', 'class' => 'hidden md:table-cell text-center'],
            ['key' => 'actions', 'label' => 'عملیات', 'sortable' => false, 'class' => 'text-left'],
        ]" :rows="$this->tickets" with-pagination>

            @scope('cell_checkbox', $ticket)
            {{-- #957: بدون نام، صفحه‌خوان این را صرفاً «چک‌باکس» می‌خواند. --}}
            {{-- #1040: وضعیت سطر $selectAll-aware است — زیر «انتخاب همه» هر سطری که
                 به‌صورت دستی ازانتخاب نشده باشد checked است (منهای دستی = id در ledgers). --}}
            <input type="checkbox"
                class="checkbox checkbox-sm checkbox-primary"
                wire:click="toggleTicketSelection({{ $ticket->id }})"
                aria-label="انتخاب تیکت {{ $ticket->ticket_code }}"
                @if($this->selectAll ? ! in_array($ticket->id, $this->selectedTickets) : in_array($ticket->id, $this->selectedTickets)) checked @endif />
            @endscope

            @scope('cell_user.person.f_name', $ticket)
            <div class="flex flex-col">
                <span class="font-bold text-sm">{{ \App\Support\ActorLabel::for($ticket->user) }}</span>
                <span class="text-xs opacity-50 font-mono">#{{ $ticket->ticket_code }}</span>
            </div>
            @endscope

            @scope('cell_priority', $ticket)
            @php
            $pClasses = ['urgent' => 'badge-error', 'normal' => 'badge-info', 'low' => 'badge-ghost'];
            $pLabels = ['urgent' => 'فوری', 'normal' => 'معمولی', 'low' => 'کم‌اهمیت'];
            @endphp
            <x-badge :value="$pLabels[$ticket->priority] ?? '---'" class="{{ $pClasses[$ticket->priority] ?? 'badge-ghost' }}" rounded />
            @endscope

            @scope('cell_status_name', $ticket)
            <x-badge :value="$ticket->status_name" class="badge-outline" rounded />
            @endscope

            @scope('cell_subject', $ticket)
            <span class="text-sm font-medium line-clamp-1 max-w-[150px]" title="{{ $ticket->subject }}">
                {{ \Illuminate\Support\Str::limit($ticket->subject, 15, '...') }}
            </span>
            @endscope

            @scope('cell_unit.name', $ticket)
            <span class="text-xs font-medium" title="{{ $ticket->unit->name }}">
                {{ \Illuminate\Support\Str::limit($ticket->unit->name, 15, '...') }}
            </span>
            @endscope

            @scope('actions', $ticket)
            <div class="flex gap-1">
                @if($ticket->status !== 'accepted' && $ticket->status !== 'rejected' && $ticket->status !== 'completed' && $ticket->unit_id == auth()->user()->person?->u_id)
                <x-ui.icon-button name="پذیرش تیکت" icon="o-check" wire:click="acceptTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-success" spinner />
                @endif
                {{-- Issue #818: reject پشت manage_unit_tickets است، پس دکمه‌اش هم همان‌جا نمایش داده می‌شود --}}
                @can('manage_unit_tickets')
                @if($ticket->status !== 'accepted' && $ticket->status !== 'rejected' && $ticket->status !== 'completed' && $ticket->unit_id == auth()->user()->person?->u_id)
                <x-ui.icon-button name="رد تیکت" icon="o-x-mark" wire:click="rejectTicket({{ $ticket->id }})"
                    wire:confirm="آیا مطمئن هستید؟" class="btn-ghost btn-sm text-error" spinner />
                @endif
                @endcan

                <x-ui.icon-button name="مشاهده تیکت" icon="o-eye" wire:click="showTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-info" spinner />

                <x-ui.icon-button name="کامنت‌های تیکت" icon="o-chat-bubble-left" wire:click="openCommentsFor({{ $ticket->id }})" class="btn-ghost btn-sm text-secondary" spinner />

                {{-- Issue #818: مودال تکمیل/ارجاع پشت manage_unit_tickets است --}}
                @can('manage_unit_tickets')
                @if($ticket->status !== 'completed' && $ticket->status !== 'rejected' && $ticket->unit_id == auth()->user()->person?->u_id)
                <x-ui.icon-button name="تکمیل تیکت" icon="o-arrow-path" wire:click="openCompletionModal({{ $ticket->id }})" class="btn-ghost btn-sm text-primary" spinner />
                @endif
                @endcan
            </div>
            @endscope
        </x-table>
    </x-card>

    {{-- Detail Modal --}}
    <x-modal wire:model="showModal" title="جزئیات تیکت" separator>
        @if($this->showingTicket)
        <div class="space-y-6 text-right" dir="rtl">
            <div>
                <h4 class="font-bold text-sm mb-2">شرح درخواست</h4>
                <p class="text-sm leading-8">{{ $this->showingTicket->content }}</p>

                {{-- نمایش وظیفه مرتبط --}}
                @if($this->showingTicket->task)
                <div class="mt-4 p-3 bg-primary/10 border border-primary/20 rounded-lg">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-calendar-days" class="w-4 h-4 text-primary" />
                        <span class="text-sm font-bold text-primary">وظیفه مرتبط:</span>
                    </div>
                    <p class="text-sm mt-1">{{ $this->showingTicket->task->title }}</p>
                    <p class="text-xs text-base-content/50 mt-1">
                        تاریخ شروع: {{ jdate($this->showingTicket->task->start_at)->format('Y/m/d') }}
                        @if($this->showingTicket->task->end_at)
                        — پایان: {{ jdate($this->showingTicket->task->end_at)->format('Y/m/d') }}
                        @endif
                        @if($this->showingTicket->task->is_completed)
                        <span class="badge badge-success badge-sm">انجام شده</span>
                        @else
                        <span class="badge badge-warning badge-sm">در انتظار</span>
                        @endif
                    </p>
                </div>
                @endif

                @php $initialFiles = $this->showingTicket->attachments->where('activity_id', null); @endphp
                @if($initialFiles->count() > 0)
                <div class="mt-4 flex flex-wrap gap-2 border-t border-base-300 pt-4">
                    @foreach($initialFiles as $file)
                    <x-button :label="$file->file_name" icon="o-paper-clip" link="{{ route('tickets.attachments.download', ['ticket' => $this->showingTicket->id, 'attachment' => $file->id]) }}"
                        class="btn-xs btn-outline" external target="_blank" />
                    @endforeach
                </div>
                @endif
            </div>

            <div class="space-y-4">
                <h4 class="font-bold text-sm border-r-4 border-primary pr-2">گردش فعالیت‌ها</h4>
                <div class="space-y-3">
                    @foreach($this->showingTicket->activities->sortByDesc('created_at') as $activity)
                    <div class="flex gap-4">
                        <div class="flex flex-col items-center">
                            <div class="w-3 h-3 rounded-full bg-primary mt-1"></div>
                            <div class="w-0.5 h-full bg-base-200"></div>
                        </div>
                        <div class="bg-base-200/50 p-3 rounded-lg w-full">
                            <div class="flex justify-between items-center mb-1">
                                <span class="font-bold text-xs">{{ \App\Support\ActorLabel::for($activity->user) }}</span>
                                <span class="text-[10px] opacity-50 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                            </div>
                            <p class="text-xs opacity-70">{{ $activity->description }}</p>
                            @if($activity->attachments->count() > 0)
                            <div class="flex gap-1 mt-2">
                                @foreach($activity->attachments as $actFile)
                                <x-ui.icon-button name="دانلود پیوست" icon="o-arrow-down-tray" link="{{ route('tickets.attachments.download', ['ticket' => $this->showingTicket->id, 'attachment' => $actFile->id]) }}"
                                    class="btn-xs btn-ghost text-primary" external target="_blank" />
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
        <x-slot:actions>
            <x-button label="بستن" wire:click="closeDetail" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>

    {{-- Completion / Forward Modal --}}
    <x-modal wire:model="isCompletionModalOpen" :title="$this->targetUnitId ? 'ارجاع تیکت' : 'تکمیل تیکت'" separator>
        <x-form wire:submit.prevent="submitAction({{ $this->showingTicketId }})" class="grid gap-4" dir="rtl">
            <div class="relative">
                <x-input label="ارجاع به واحد مقصد (اختیاری):"
                    wire:model.live="unitSearch"
                    placeholder="جستجوی نام واحد..."
                    icon="o-magnifying-glass" />

                @if(!empty($units))
                <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-lg shadow-xl max-h-40 overflow-y-auto">
                    @foreach($units as $u)
                    <button type="button" wire:click="selectTargetUnit({{ $u['id'] }})"
                        class="w-full text-right px-4 py-2 hover:bg-primary hover:text-white text-sm transition-colors border-b last:border-0">
                        {{ $u['name'] }}
                    </button>
                    @endforeach
                </div>
                @endif

                @if($this->targetUnitId)
                <div class="mt-2 flex items-center gap-2 text-info text-sm">
                    <x-icon name="o-check-circle" />
                    <span class="font-bold">مقصد: {{ $this->targetUnitName }}</span>
                    <x-ui.icon-button name="پاک کردن واحد هدف" icon="o-x-mark" wire:click="$set('targetUnitId', null)" class="btn-ghost btn-xs" />
                </div>
                @endif
            </div>

            <x-textarea
                label="توضیحات یا گزارش نهایی:"
                wire:model="completionNote"
                rows="4" />

            <x-file wire:model="completionFiles" label="پیوست مستندات" multiple icon="o-cloud-arrow-up" />

            <div class="flex gap-4">
                <x-button type="submit"
                    label="{{ $this->targetUnitId ? 'تایید و ارجاع' : 'تکمیل و بستن' }}"
                    class="{{ $this->targetUnitId ? 'btn-info' : 'btn-success' }} flex-1" spinner />
                <x-button type="button" label="انصراف" wire:click="closeCompletionModal" class="btn-ghost flex-1" />
            </div>
        </x-form>
    </x-modal>

    {{-- #1690: Bulk Actions modal. `x-modal` is CspModal, whose `data-model`
         binding needs no JS change. The title deliberately avoids the bulk-bar
         button labels so the permission-visibility tests keep seeing those
         labels only where `@can('manage_unit_tickets')` renders them. --}}
    <x-modal wire:model="showBulkModal"
        :title="$this->bulkAction === 'forward' ? 'عملیات دسته‌ای — ارجاع تیکت‌ها' : 'عملیات دسته‌ای — تکمیل تیکت‌ها'"
        separator>
        <div class="grid gap-4" dir="rtl">
            @if($this->bulkAction === 'forward')
            <div class="relative">
                <x-input label="ارجاع به واحد مقصد:"
                    wire:model.live="bulkUnitSearch"
                    placeholder="جستجوی نام واحد..."
                    icon="o-magnifying-glass" />

                @if(!empty($this->bulkUnits))
                <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-lg shadow-xl max-h-40 overflow-y-auto">
                    @foreach($this->bulkUnits as $u)
                    <button type="button" wire:click="selectBulkTargetUnit({{ $u['id'] }})"
                        class="w-full text-right px-4 py-2 hover:bg-primary hover:text-white text-sm transition-colors border-b last:border-0">
                        {{ $u['name'] }}
                    </button>
                    @endforeach
                </div>
                @endif

                @if($this->bulkTargetUnitId)
                <div class="mt-2 flex items-center gap-2 text-info text-sm">
                    <x-icon name="o-check-circle" />
                    <span class="font-bold">مقصد: {{ $this->bulkTargetUnitName }}</span>
                    <x-ui.icon-button name="پاک کردن واحد هدف" icon="o-x-mark" wire:click="$set('bulkTargetUnitId', null)" class="btn-ghost btn-xs" />
                </div>
                @endif

                @error('bulkTargetUnitId')
                <div class="mt-2 text-error text-xs">{{ $message }}</div>
                @enderror
            </div>
            @endif

            <x-textarea label="توضیحات (اختیاری):" wire:model="bulkNote" rows="4" />

            <div class="flex gap-4">
                <x-button type="button"
                    label="{{ $this->bulkAction === 'forward' ? 'تایید و ارجاع' : 'تکمیل و بستن' }}"
                    wire:click="executeBulkAction"
                    class="{{ $this->bulkAction === 'forward' ? 'btn-info' : 'btn-success' }} flex-1" spinner />
                <x-button type="button" label="انصراف" wire:click="closeBulkModal" class="btn-ghost flex-1" />
            </div>
        </div>
    </x-modal>

    {{-- Ticket Comments modal --}}
    <livewire:tickets.ticket-comments />
</div>

@assets
    {{-- Issue #1182: jalaliDatepicker is page-specific and used to ship in the
         global layout on every page. `@assets` keeps the tag in <head>, so the
         library is defined before this page's first `startWatch()` call — on a
         full load and before the swap on `wire:navigate`. --}}
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
@endassets

<script nonce="{{ Vite::cspNonce() }}">
    const initJdp = () => {
        jalaliDatepicker.startWatch();

        const fromInput = document.getElementById('filter_date_from');
        const toInput = document.getElementById('filter_date_to');

        if (fromInput) {
            fromInput.addEventListener('jdp:change', e => {
                $wire.set('dateFrom', e.target.value);
            });
        }
        if (toInput) {
            toInput.addEventListener('jdp:change', e => {
                $wire.set('dateTo', e.target.value);
            });
        }
    };

    initJdp();
    document.addEventListener('livewire:navigated', initJdp);
</script>
