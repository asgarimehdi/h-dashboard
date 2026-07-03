<?php

use App\Models\Ticket;
use App\Models\Unit;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

new class extends Component
{
    use WithPagination;

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

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function selectUnitForFilter($id): void
    {
        $this->selectedUnitId = $id;
        $this->unitSearch = '';
        $this->resetPage();
    }

    public function render()
    {
        $units = [];
        if (strlen($this->unitSearch) > 1) {
            $units = Unit::where('name', 'like', '%' . $this->unitSearch . '%')
                ->where('can_receive_tickets', true)
                ->limit(10)->get();
        }

        $query = Ticket::with(['user', 'unit', 'assignee', 'activities', 'task'])->accessible();

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
            $miladiFrom = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateFrom)->toCarbon()->startOfDay();
            $query->where('created_at', '>=', $miladiFrom);
        }

        if ($this->dateTo) {
            $miladiTo = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateTo)->toCarbon()->endOfDay();
            $query->where('created_at', '<=', $miladiTo);
        }

        return $this->view([
            'tickets' => $query->latest()->paginate(15),
            'filterUnits' => $units,
            'currentUnit' => $this->selectedUnitId ? Unit::find($this->selectedUnitId) : null,
        ]);
    }

    public function showTicket($id): void
    {
        $this->showingTicket = Ticket::with([
            'user',
            'unit',
            'attachments',
            'activities.user',
            'activities.attachments'
        ])->findOrFail($id);
        $this->showModal = true;
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
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

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
                    <button wire:click="selectUnitForFilter({{ $u->id }})"
                        class="w-full text-right px-4 py-2 hover:bg-primary hover:text-white text-sm transition-colors border-b last:border-0">
                        {{ $u->name }}
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

        @if($this->selectedUnitId)
        <div class="mb-4">
            <x-badge value="فیلتر: {{ $currentUnit->name }}" class="badge-warning" icon-right="o-x-mark" wire:click="$set('selectedUnitId', null)" />
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
        ]" :rows="$tickets" with-pagination>

            @scope('cell_ticket_code', $ticket)
            <span class="font-mono text-xs">#{{ $ticket->ticket_code }}</span>
            @endscope

            @scope('cell_user.person.f_name', $ticket)
            <span class="text-sm font-bold">{{ $ticket->user->person?->f_name }} {{ $ticket->user->person?->l_name }}</span>
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
            <x-button icon="o-eye" wire:click="showTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-primary" spinner />
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
                                <span class="font-bold text-xs">{{ $activity->user->full_name }}</span>
                                <span class="text-[10px] opacity-50 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                            </div>
                            <p class="text-xs opacity-70">{{ $activity->description }}</p>
                            @if($activity->attachments->count() > 0)
                            <div class="flex gap-1 mt-2">
                                @foreach($activity->attachments as $actFile)
                                <x-button icon="o-paper-clip" link="{{ Storage::url($actFile->file_path) }}"
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
