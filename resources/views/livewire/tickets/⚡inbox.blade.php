<?php

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\TaskActivity;
use App\Rules\TicketTargetUnit;
use App\Services\AccessService;
use App\Support\JalaliDateRange;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    use WithPagination;
    use WithFileUploads;
    // Fully qualified: a top-of-file import would shift the anonymous class's
    // identity line and break every line-keyed PHPStan-baseline entry for it.
    // The top `JalaliDateRange` import is now unused (this trait owns the date
    // parse) but stays in place so the identity line keeps its beta position.
    use \App\Traits\TicketsQueryScopes;
    use \App\Traits\TicketsInboxFlow;

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
            $units = Unit::where('name', 'like', '%' . $this->unitSearch . '%')
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
     * The filter predicate the inbox table itself runs — viewMode scope
     * (received = `accessible()`, sent = owner/assignee/activity paths), tab
     * status filter, Jalali date window and search — appended onto whichever
     * base query the caller builds. Extracted unchanged from `tickets()` so
     * #1040's select-all expansion and count see EXACTLY the rows the
     * operator sees, instead of a copy that could drift.
     *
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\Ticket> $query
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

        $query = Ticket::query();
        $this->applyInboxFilters($query);

        return $query->whereNotIn('id', $this->selectedTickets)->count();
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
        if ($this->selectedTicketsCount() === 0) {
            $this->dispatch('swal', ['title' => 'هیچ تیکتی انتخاب نشده', 'icon' => 'warning']);
            return;
        }
        $this->bulkAction = $action;
        $this->showBulkModal = true;
    }

    public function executeBulkAction(): void
    {
        // #1040: the effective selection (select-all seen through the page's
        // filter, minus manual un-picks, or the manual picks alone) has to be
        // resolved BEFORE any state reset — this method is also the only
        // place that materializes it.
        if ($this->selectedTicketsCount() === 0) {
            return;
        }

        // Issue #818: the bulk `complete` branch closes tickets in bulk, which is
        // the same state change the single-ticket path needed
        // manage_unit_tickets for. `forward` stays open to the route gate
        // because it does not move the ticket out of its unit (its own latent
        // issue — see #818 "Out of scope").
        if ($this->bulkAction === 'complete') {
            $this->authorizeMutation();
        }

        $now = now();
        $userId = auth()->id();
        $bulkNote = $this->bulkNote;

        // Get accessible unit IDs for scope filtering
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // ۱. یک کوئری برای همه تیکت‌ها - فیلتر تیکت‌های قبلاً completed شده + محدوده سازمانی
        //
        // #1040: «انتخاب همه» is NOT the whole accessible pool any more —
        // the approved semantics expand it right here, against the page's
        // ACTIVE filter/search/view-mode, minus the rows the operator
        // un-picked. The manual path is unchanged (`selectedTickets` holds
        // only hand-picked ids in that mode).
        if ($this->selectAll) {
            $scope = Ticket::query();
            $this->applyInboxFilters($scope);
            $ticketIds = $scope
                ->whereNotIn('id', $this->selectedTickets)
                ->where('status', '!=', 'completed')
                ->whereIn('unit_id', $accessibleIds)
                ->pluck('id');
        } else {
            $ticketIds = Ticket::whereIn('id', $this->selectedTickets)
                ->where('status', '!=', 'completed')
                ->whereIn('unit_id', $accessibleIds)
                ->pluck('id');
        }

        if ($ticketIds->isEmpty()) {
            $this->dispatch('swal', ['title' => 'هیچ تیکت قابل پردازشی یافت نشد', 'icon' => 'warning']);
            $this->closeAllModals();
            return;
        }

        DB::transaction(function () use ($ticketIds, $now, $userId, $bulkNote) {
            // Issue #531: capture old statuses BEFORE the bulk update
            $oldStatuses = Ticket::whereIn('id', $ticketIds)
                ->pluck('status', 'id');

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
                $activityRows = $ticketIds->map(fn($id) => [
                    'ticket_id' => $id,
                    'user_id' => $userId,
                    'action' => 'completed',
                    'description' => 'تکمیل دسته‌ای تیکت' . ($bulkNote ? ": {$bulkNote}" : ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();
                TaskActivity::insert($activityRows);

                // ActivityLogService - با استفاده از وضعیت قبل از آپدیت
                $tickets = Ticket::whereIn('id', $ticketIds)->get(['id', 'ticket_code', 'status', 'task_id']);
                $completedTaskIds = $tickets->pluck('task_id')->filter()->unique();

                foreach ($tickets as $ticket) {
                    $oldStatus = $oldStatuses->get($ticket->id, $ticket->status);
                    \App\Services\ActivityLogService::updated($ticket, ['status' => $oldStatus], ['status' => 'completed'], "تکمیل دسته‌ای تیکت {$ticket->ticket_code}");
                }

                // Plan 005: Auto-complete parent tasks where all child tickets are completed
                foreach ($completedTaskIds as $taskId) {
                    $incompleteCount = Ticket::where('task_id', $taskId)
                        ->where('status', '!=', 'completed')
                        ->count();
                    if ($incompleteCount === 0) {
                        \App\Models\Todo::where('id', $taskId)->update(['is_completed' => true]);
                    }
                }
            }

            if ($this->bulkAction === 'forward') {
                Ticket::whereIn('id', $ticketIds)->update([
                    'status' => 'forwarded',
                    'current_assignee_id' => null,
                ]);
                // Issue #378/#389: bulk update bypasses Eloquent events — bump caches manually
                $cache = app(CacheInvalidationServiceInterface::class);
                $cache->increment('report_tickets');
                $cache->increment('gis');
                $cache->increment('calendar');
                $cache->increment('dashboard');

                $activityRows = $ticketIds->map(fn($id) => [
                    'ticket_id' => $id,
                    'user_id' => $userId,
                    'action' => 'forwarded',
                    'description' => 'ارجاع دسته‌ای تیکت' . ($bulkNote ? ": {$bulkNote}" : ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();
                TaskActivity::insert($activityRows);

                // Issue #531: use actual old status instead of hardcoded 'accepted'
                $tickets = Ticket::whereIn('id', $ticketIds)->get(['id', 'ticket_code', 'status']);
                foreach ($tickets as $ticket) {
                    $oldStatus = $oldStatuses->get($ticket->id, $ticket->status);
                    \App\Services\ActivityLogService::updated($ticket, ['status' => $oldStatus], ['status' => 'forwarded'], "ارجاع دسته‌ای تیکت {$ticket->ticket_code}");
                }
            }
        });

        // #1001: report the tickets actually processed after scope/status
        // filtering — not how many the operator selected.
        $this->dispatch('swal', ['title' => "{$ticketIds->count()} تیکت با موفقیت پردازش شد", 'icon' => 'success']);
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

    <x-help:modal wireModel="showHelpModal" />

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
        @if($this->selectedTicketsCount() > 0)
        <div class="flex items-center gap-3 p-3 mb-4 bg-primary/10 rounded-xl border border-primary/20">
            <span class="text-sm font-bold text-primary">{{ $this->selectedTicketsCount() }} تیکت انتخاب شده</span>
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
                {{ Str::limit($ticket->subject, 15, '...') }}
            </span>
            @endscope

            @scope('cell_unit.name', $ticket)
            <span class="text-xs font-medium" title="{{ $ticket->unit->name }}">
                {{ Str::limit($ticket->unit->name, 15, '...') }}
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
                    <x-button :label="$file->file_name" icon="o-paper-clip" link="{{ asset('storage/' . $file->file_path) }}"
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
                                <x-ui.icon-button name="دانلود پیوست" icon="o-arrow-down-tray" link="{{ asset('storage/' . $actFile->file_path) }}"
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
                <x-button label="انصراف" @click="$wire.isCompletionModalOpen = false" class="btn-ghost flex-1" />
            </div>
        </x-form>
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

<script>
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
