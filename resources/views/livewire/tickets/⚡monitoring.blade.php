<?php

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Services\AccessService;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    use WithPagination;
    use Toast;

    public bool $showHelpModal = false;

    #[Url]
    public string $search = '';
    #[Url]
    public string $statusFilter = 'all';
    #[Url]
    public ?int $selectedUnitId = null;
    #[Url]
    public string $unitSearch = '';
    #[Url]
    public string $dateFrom = '';
    #[Url]
    public string $dateTo = '';

    public bool $showModal = false;
    public ?Ticket $showingTicket = null;
    public bool $modalDetail = false;

    public array $filterUnits = [];
    public ?Unit $currentUnit = null;

    public function mount(): void
    {
        $this->loadData();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'selectedUnitId', 'dateFrom', 'dateTo'])) {
            $this->resetPage();
        }
        $this->loadData();
    }

    public function loadData(): void
    {
        // #918: both unit lookups were org-wide, so a county-scoped viewer
        // could enumerate every ticket-receiving unit in the organization —
        // the dropdown by name, `$currentUnit` by `?selectedUnitId=` alone
        // (`#[Url]`, no interaction needed). Ticket rows were always scoped
        // (`tickets()` is `->accessible()`, `showTicket()` re-checks
        // `in_array`), so this was a unit-directory disclosure, not a
        // data-access bypass.
        //
        // Both predicates are UNCONDITIONAL `whereIn`, never `when($ids, …)`
        // or `! empty($ids)`: those are the fail-open spellings behind #813 /
        // #819 / #833, where an empty scope drops the predicate instead of
        // returning nobody. `accessibleUnitIds()` is legitimately `[]` for an
        // account with no `user_units` row and no `person.u_id`, and `[]`
        // means "in scope of nothing" — it must compile to `0 = 1`.
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $units = [];
        if (mb_strlen($this->unitSearch) > 1) {
            $units = Unit::where('name', 'like', '%' . $this->unitSearch . '%')
                ->where('can_receive_tickets', true)
                ->whereIn('id', $accessibleIds)
                ->limit(10)->get()->toArray();
        }
        $this->filterUnits = $units;

        // Scope only — deliberately NOT `can_receive_tickets`. This lookup
        // previously leaked ANY unit in the org, including units that cannot
        // receive tickets (the province headquarters among them), so it is
        // broader than the dropdown. Eligibility is a separate question and
        // belongs on the dropdown, not here.
        // The `whereIn` sits inside a closure so the chain stays an
        // `Eloquent\Builder` and `find()` keeps its `Unit|null` return type —
        // a bare `whereIn()` re-types the rest of the chain through
        // `Query\Builder`'s `@mixin` and yields `stdClass`. See AGENTS.md,
        // "Eloquent chains vs PHPStan (no larastan)".
        $this->currentUnit = $this->selectedUnitId
            ? Unit::query()
                ->where(fn ($q) => $q->whereIn('id', $accessibleIds))
                ->find($this->selectedUnitId)
            : null;
    }

    #[Computed]
    public function tickets()
    {
        // #906: `deleted_at` is selected on purpose — `ActorLabel` tells a
        // soft-deleted creator («کاربر غیرفعال») from a genuinely ownerless
        // ticket («سیستم»), and `trashed()` reads that column. Without the
        // soft-deleted rows in the result both cases resolve to `null` and are
        // indistinguishable.
        $query = Ticket::with(['user' => fn ($q) => \App\Support\ActorLabel::eagerLoadActor($q), 'unit:id,name'])->accessible();

        if ($this->selectedUnitId) {
            $query->where('unit_id', $this->selectedUnitId);
        }

        if ($this->statusFilter === 'pending') {
            $query->whereIn('status', ['created', 'forwarded']);
        } elseif ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('subject', 'like', '%' . $this->search . '%')
                    ->orWhere('ticket_code', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->dateFrom) {
            try {
                $miladiFrom = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateFrom)->toCarbon()->startOfDay();
                $query->where('created_at', '>=', $miladiFrom);
            } catch (\Throwable) {
                // Invalid Jalali date string — ignore filter
            }
        }

        if ($this->dateTo) {
            try {
                $miladiTo = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateTo)->toCarbon()->endOfDay();
                $query->where('created_at', '<=', $miladiTo);
            } catch (\Throwable) {
                // Invalid Jalali date string — ignore filter
            }
        }

        return $query->latest()->paginate(20);
    }

    /**
     * A node was clicked: apply it as this page's unit filter.
     *
     * #918: this is a PUBLIC Livewire method, so the id arrives from the
     * client and the guard belongs here rather than being inherited from the
     * caller's ordering — same contract as `unit.tree`'s `selectNode`
     * (resources/views/livewire/unit/tree.blade.php:29-31). `loadData()`
     * scopes the lookup too, but that makes this method safe only by
     * consequence; keeping the check local means the guarantee does not
     * depend on that ordering holding.
     */
    public function selectUnitForFilter($id): void
    {
        if (! in_array((int) $id, app(AccessService::class)->accessibleUnitIds(), true)) {
            return;
        }

        $this->selectedUnitId = $id;
        $this->unitSearch = '';
        $this->resetPage();
        $this->loadData();
    }

    public function showTicket($id): void
    {
        // Check organizational scope
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        /** @var Ticket|null $ticket */
        $ticket = Ticket::with([
            // #906: soft-deleted users are kept in the result so their creator
            // renders «کاربر غیرفعال» instead of collapsing into the «سیستم» a
            // truly ownerless ticket gets.
            'user' => fn ($q) => \App\Support\ActorLabel::eagerLoadActor($q),
            'unit',
            'attachments',
            'activities.user' => fn ($q) => \App\Support\ActorLabel::eagerLoadActor($q),
            'activities.attachments'
        ])->findOrFail($id);

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            $this->error('شما مجاز به مشاهده این تیکت نیستید.', position: 'toast-bottom');
            return;
        }

        // #847: same leak as the inbox — the ticket is scoped, the `task`
        // relation is not. Gate it on the viewer's scope so legacy
        // cross-unit links stop rendering their todo here. `setRelation()`
        // rather than a plain assignment: see the inbox for why.
        $ticket->setRelation('task', $this->taskIfInScope($ticket->task_id, $accessibleIds));

        $this->showingTicket = $ticket;
        $this->showModal = true;
    }

    /**
     * The ticket's task, or null when it is outside the viewer's scope.
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
        $this->showModal = false;
        $this->showingTicket = null;
    }
};
?>

<div>
    <x-header title="مانیتورینگ تیکت‌ها" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="tickets" wireModel="showHelpModal" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <div class="relative">
                <x-input
                    wire:model.live="unitSearch"
                    placeholder="جستجوی واحد..."
                    icon="o-building-office"
                    class="w-64" />

                @if(!empty($this->unitSearch) && !empty($filterUnits))
                <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-lg shadow-xl overflow-hidden">
                    @foreach($filterUnits as $u)
                    <button wire:click="selectUnitForFilter({{ $u['id'] }})"
                        class="w-full text-right px-4 py-2 hover:bg-primary hover:text-white text-sm transition-colors border-b last:border-0">
                        {{ $u['name'] }}
                    </button>
                    @endforeach
                </div>
                @endif
            </div>

            <div class="flex gap-2" wire:ignore>
                <input data-jdp id="filter_date_from" placeholder="از تاریخ"
                    class="input input-bordered input-sm w-28 text-center cursor-pointer" readonly>
                <input data-jdp id="filter_date_to" placeholder="تا تاریخ"
                    class="input input-bordered input-sm w-28 text-center cursor-pointer" readonly>
            </div>

            <div class="flex gap-2">
                @foreach(['all' => 'همه', 'pending' => 'انتظار', 'accepted' => 'انجام', 'completed' => 'تکمیل'] as $key => $label)
                <x-button
                    label="{{ $label }}"
                    wire:click="$set('statusFilter', '{{ $key }}')"
                    class="btn-xs {{ $this->statusFilter === $key ? 'btn-primary' : 'btn-outline' }}" />
                @endforeach
            </div>

            <div class="flex-1">
                <x-input
                    placeholder="جستجوی کد یا موضوع..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full" />
            </div>
        </div>

        @if($this->selectedUnitId && $this->currentUnit)
        <div class="mb-4">
            <x-badge value="فیلتر: {{ $currentUnit?->name ?? 'نامشخص' }}" class="badge-warning" icon-right="o-x-mark" wire:click="$set('selectedUnitId', null)" />
        </div>
        @endif

        <x-table :headers="[
            ['key' => 'ticket_code', 'label' => 'شناسه'],
            ['key' => 'user.person.f_name', 'label' => 'فرستنده'],
            ['key' => 'unit.name', 'label' => 'واحد مقصد', 'class' => 'hidden md:table-cell'],
            ['key' => 'status', 'label' => 'وضعیت', 'class' => 'hidden md:table-cell'],
            ['key' => 'duration', 'label' => 'انتظار', 'class' => 'hidden md:table-cell'],
            ['key' => 'subject', 'label' => 'موضوع'],
            ['key' => 'actions', 'label' => 'جزئیات', 'sortable' => false],
        ]" :rows="$this->tickets" with-pagination>

            @scope('cell_ticket_code', $ticket)
            <span class="font-mono text-xs">#{{ $ticket->ticket_code }}</span>
            @endscope

            @scope('cell_user.person.f_name', $ticket)
            <span class="text-sm font-bold">{{ \App\Support\ActorLabel::for($ticket->user) }}</span>
            @endscope

            @scope('cell_unit.name', $ticket)
            <span class="text-xs">{{ Str::limit($ticket->unit->name, 15, '...') }}</span>
            @endscope

            @scope('cell_status', $ticket)
            <x-badge :value="$ticket->status_name" class="{{ $ticket->status === 'accepted' ? 'badge-info' : 'badge-ghost' }}" rounded />
            @endscope

            @scope('cell_duration', $ticket)
            <span class="text-xs {{ $ticket->waiting_duration['class'] }}">{{ $ticket->waiting_duration['text'] }}</span>
            @endscope

            @scope('cell_subject', $ticket)
            <span class="text-sm line-clamp-1 max-w-[150px]" title="{{ $ticket->subject }}">
                {{ Str::limit($ticket->subject, 15, '...') }}
            </span>
            @endscope

            @scope('actions', $ticket)
            <x-ui.icon-button name="مشاهده تیکت" icon="o-eye" wire:click="showTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-primary" spinner />
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
                    <x-button :label="$file->file_name" icon="o-arrow-down-tray" link="{{ Storage::url($file->file_path) }}"
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
                                <x-ui.icon-button name="دانلود پیوست" icon="o-paper-clip" link="{{ Storage::url($actFile->file_path) }}"
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
</div>

<script>
    const initMonitoringJdp = () => {
        if (typeof jalaliDatepicker !== 'undefined') {
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
        }
    };

    initMonitoringJdp();
    document.addEventListener('livewire:navigated', initMonitoringJdp);
</script>
