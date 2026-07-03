<?php

use App\Models\Ticket;
use App\Models\Unit;
use App\Services\AccessService;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use WithPagination;
    use WithFileUploads;

    #[Url]
    public string $search = '';
    #[Url]
    public string $unitSearch = '';
    #[Url]
    public ?int $targetUnitId = null;
    #[Url]
    public string $targetUnitName = '';
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

    public bool $isCompletionModalOpen = false;
    public string $completionNote = '';
    public array $completionFiles = [];
    public ?Ticket $showingTicket = null;
    public ?int $showingTicketId = null;
    public bool $showModal = false;

    // Bulk Actions
    public array $selectedTickets = [];
    public bool $selectAll = false;
    public bool $showBulkModal = false;
    public string $bulkAction = '';
    public string $bulkNote = '';

    // Data properties
    public $tickets;
    public array $units = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $user = auth()->user();
        $units = [];

        if (strlen($this->unitSearch) > 1) {
            $units = Unit::where('name', 'like', '%' . $this->unitSearch . '%')
                ->where('can_receive_tickets', true)
                ->where('id', '!=', auth()->user()->person?->u_id)
                ->limit(5)
                ->get();
        }

        $query = Ticket::with(['user', 'unit', 'assignee', 'task']);

        if ($this->viewMode === 'received') {
            $query->accessible();
        } else {
            $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds($user);
            $query->where(function ($q) use ($user, $accessibleUnitIds) {
                $q->where('user_id', $user->id)
                    ->orWhere(function ($subQ) use ($user, $accessibleUnitIds) {
                        $subQ->whereHas('activities', function ($activityQuery) use ($user) {
                            $activityQuery->where('user_id', $user->id);
                        })
                            ->whereNotIn('unit_id', $accessibleUnitIds);
                    });
            });
        }

        if ($this->statusFilter === 'pending') {
            $query->whereIn('status', ['created', 'forwarded']);
        } elseif ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->dateFrom) {
            $miladiFrom = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateFrom)->toCarbon()->startOfDay();
            $query->where('created_at', '>=', $miladiFrom);
        }
        if ($this->dateTo) {
            $miladiTo = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateTo)->toCarbon()->endOfDay();
            $query->where('created_at', '<=', $miladiTo);
        }
        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('subject', 'like', '%' . $this->search . '%')
                    ->orWhere('ticket_code', 'like', '%' . $this->search . '%')
                    ->orWhere('content', 'like', '%' . $this->search . '%');
            });
        }

        $this->tickets = $query->latest()->paginate(5);
        $this->units = $units;
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
        $this->showingTicket = null;
        $this->showingTicketId = null;
        $this->reset([
            'completionNote', 'completionFiles', 'targetUnitId',
            'targetUnitName', 'unitSearch', 'selectedTickets',
            'selectAll', 'showBulkModal', 'bulkAction', 'bulkNote',
        ]);
    }

    // ===== Bulk Actions =====
    public function toggleTicketSelection($ticketId): void
    {
        if (in_array($ticketId, $this->selectedTickets)) {
            $this->selectedTickets = array_values(array_diff($this->selectedTickets, [$ticketId]));
        } else {
            $this->selectedTickets[] = $ticketId;
        }
    }

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedTickets = [];
            $this->selectAll = false;
        } else {
            $this->selectedTickets = Ticket::whereIn('unit_id', $this->getAccessibleUnitIds())
                ->whereIn('status', ['created', 'forwarded', 'accepted'])
                ->pluck('id')->toArray();
            $this->selectAll = true;
        }
    }

    public function openBulkModal($action): void
    {
        if (empty($this->selectedTickets)) {
            $this->dispatch('swal', ['title' => 'هیچ تیکتی انتخاب نشده', 'icon' => 'warning']);
            return;
        }
        $this->bulkAction = $action;
        $this->showBulkModal = true;
    }

    public function executeBulkAction(): void
    {
        if (empty($this->selectedTickets)) return;

        $count = count($this->selectedTickets);

        DB::transaction(function () use ($count) {
            foreach ($this->selectedTickets as $ticketId) {
                $ticket = Ticket::find($ticketId);
                if (!$ticket) continue;

                match($this->bulkAction) {
                    'complete' => $this->bulkCompleteTicket($ticket),
                    'forward' => $this->bulkForwardTicket($ticket),
                    default => null,
                };
            }
        });

        $this->dispatch('swal', ['title' => "{$count} تیکت با موفقیت پردازش شد", 'icon' => 'success']);
        $this->closeAllModals();
        $this->resetPage();
    }

    private function bulkCompleteTicket(Ticket $ticket): void
    {
        $ticket->update(['status' => 'completed', 'completed_at' => now()]);
        $ticket->activities()->create([
            'user_id' => auth()->id(),
            'action' => 'completed',
            'description' => 'تکمیل دسته‌ای تیکت' . ($this->bulkNote ? ": {$this->bulkNote}" : ''),
        ]);

        \App\Services\ActivityLogService::updated($ticket, ['status' => $ticket->status], ['status' => 'completed'], "تکمیل دسته‌ای تیکت {$ticket->ticket_code}");
    }

    private function bulkForwardTicket(Ticket $ticket): void
    {
        $ticket->update([
            'status' => 'forwarded',
            'current_assignee_id' => null,
        ]);
        $ticket->activities()->create([
            'user_id' => auth()->id(),
            'action' => 'forwarded',
            'description' => 'ارجاع دسته‌ای تیکت' . ($this->bulkNote ? ": {$this->bulkNote}" : ''),
        ]);

        \App\Services\ActivityLogService::updated($ticket, ['status' => 'accepted'], ['status' => 'forwarded'], "ارجاع دسته‌ای تیکت {$ticket->ticket_code}");
    }

    private function getAccessibleUnitIds(): array
    {
        return app(AccessService::class)->accessibleUnitIds();
    }

    public function switchView($mode): void
    {
        $this->viewMode = $mode;
        $this->statusFilter = 'pending';
        $this->resetPage();
    }

    public function showTicket($id): void
    {
        $this->showingTicket = Ticket::with(['attachments', 'activities.attachments', 'activities.user', 'user', 'unit'])->findOrFail($id);
        $this->showModal = true;
    }

    public function closeDetail(): void
    {
        $this->showingTicket = null;
        $this->reset(['targetUnitId', 'showingTicket', 'targetUnitName', 'unitSearch', 'forwardNote']);
        $this->showModal = false;
    }

    public function selectTargetUnit($id, $name): void
    {
        $this->targetUnitId = $id;
        $this->targetUnitName = $name;
        $this->unitSearch = '';
    }

    public function forward(): void
    {
        $this->validate([
            'targetUnitId' => 'required|exists:units,id',
            'forwardNote' => 'nullable|string|max:500',
        ]);

        try {
            \DB::transaction(function () {
                $this->showingTicket->update([
                    'unit_id' => $this->targetUnitId,
                    'status' => 'forwarded',
                    'current_assignee_id' => null
                ]);

                $this->showingTicket->activities()->create([
                    'user_id' => auth()->id(),
                    'action' => 'forwarded',
                    'description' => "ارجاع تیکت به واحد: " . $this->targetUnitName . " - توضیحات: " . $this->forwardNote,
                ]);
            });

            $this->dispatch('swal', ['title' => 'تیکت با موفقیت ارجاع شد', 'icon' => 'success']);
            $this->closeDetail();
        } catch (\Exception $e) {
            $this->dispatch('swal', ['title' => 'خطا در انجام عملیات', 'icon' => 'error']);
        }
    }

    public function acceptTicket($ticketId): void
    {
        $ticket = Ticket::findOrFail($ticketId);
        DB::transaction(function () use ($ticket) {
            $ticket->update([
                'status' => 'accepted',
                'current_assignee_id' => auth()->id(),
                'accepted_at' => now(),
            ]);

            $ticket->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'accepted',
                'description' => 'تیکت توسط کارشناس تایید شد و مسئولیت آن پذیرفته شد.'
            ]);
        });

        // ثبت فعالیت
        \App\Services\ActivityLogService::updated(
            $ticket,
            ['status' => 'created'],
            ['status' => 'accepted'],
            "پذیرش تیکت {$ticket->ticket_code}"
        );

        $this->dispatch('swal', ['title' => 'تیکت پذیرفته شد', 'icon' => 'success']);
        $this->closeDetail();
    }

    public function rejectTicket($ticketId): void
    {
        try {
            $ticket = Ticket::where('unit_id', auth()->user()->person?->u_id)->findOrFail($ticketId);

            \DB::transaction(function () use ($ticket) {
                $ticket->update([
                    'status' => 'rejected',
                    'current_assignee_id' => auth()->id(),
                ]);

                $ticket->activities()->create([
                    'user_id' => auth()->id(),
                    'action' => 'rejected',
                    'description' => 'تیکت توسط واحد ' . (auth()->user()->person?->unit?->name ?? 'بدون واحد') . ' رد شد.',
                ]);
            });

            $this->dispatch('swal', ['title' => 'تیکت با موفقیت رد شد', 'icon' => 'info']);
            $this->closeDetail();
        } catch (\Exception $e) {
            $this->dispatch('swal', ['title' => 'خطایی رخ داد', 'icon' => 'error']);
            $this->closeDetail();
        }
    }

    public function openCompletionModal($id): void
    {
        $this->showingTicketId = $id;
        $this->showingTicket = Ticket::find($id);
        $this->isCompletionModalOpen = true;
    }

    public function submitAction($id = null): void
    {
        $finalId = $id ?? $this->showingTicketId;
        $ticket = Ticket::findOrFail($finalId);

        if ($ticket->status !== 'accepted' && !$this->targetUnitId) {
            $this->addError('completionNote', 'تیکت تایید نشده را نمی‌توان مختومه کرد. ابتدا ارجاع دهید یا تایید کنید.');
            return;
        }

        $actionType = $this->targetUnitId ? 'forwarded' : 'completed';

        $this->validate([
            'completionNote' => $actionType === 'completed' ? 'required|min:5' : 'nullable|max:1000',
            'completionFiles' => 'nullable|array|max:5',
            'completionFiles.*' => 'file|mimes:jpg,jpeg,png,pdf,zip,rar,docx,xlsx|max:5120',
        ]);

        try {
            DB::beginTransaction();

            if ($actionType === 'forwarded') {
                $ticket->update([
                    'unit_id' => $this->targetUnitId,
                    'status' => 'forwarded',
                    'current_assignee_id' => null,
                ]);
                $description = "ارجاع تیکت به واحد: {$this->targetUnitName}";
                if ($this->completionNote) {
                    $description .= " | توضیحات: {$this->completionNote}";
                }
                $message = "تیکت با موفقیت به واحد {$this->targetUnitName} ارجاع شد.";

                // ارسال اعلان به واحد مقصد
                \App\Services\NotificationService::notifyUnit(
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
                $message = "تیکت با موفقیت مختومه و بسته شد.";

                // اگر وظیفه مرتبطی دارد و تمام تیکت‌های آن وظیفه بسته شده‌اند، وظیفه را تکمیل کن
                if ($ticket->task_id) {
                    $relatedTicket = \App\Models\Ticket::where('task_id', $ticket->task_id)
                        ->where('status', '!=', 'completed')
                        ->where('id', '!=', $ticket->id)
                        ->count();
                    if ($relatedTicket === 0) {
                        $ticket->task->update(['is_completed' => true]);
                        $message .= " وظیفه مرتبط نیز تکمیل شد.";
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
                    $path = $file->store('attachments', 'public');
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

            $this->reset(['isCompletionModalOpen', 'showingTicket', 'completionNote', 'completionFiles', 'targetUnitId', 'targetUnitName', 'unitSearch']);

            $this->dispatch('swal', [
                'title' => 'عملیات موفق',
                'text' => $message,
                'icon' => 'success'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->addError('completionNote', 'خطایی در ثبت عملیات رخ داد: ' . $e->getMessage());
        }
    }

    public function removeFile($index): void
    {
        array_splice($this->completionFiles, $index, 1);
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
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

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

        {{-- نوار Bulk Actions --}}
        @if(count($selectedTickets) > 0)
        <div class="flex items-center gap-3 p-3 mb-4 bg-primary/10 rounded-xl border border-primary/20">
            <span class="text-sm font-bold text-primary">{{ count($selectedTickets) }} تیکت انتخاب شده</span>
            <div class="flex gap-2 mr-auto">
                <x-button icon="o-check-circle" label="تکمیل دسته‌ای" wire:click="openBulkModal('complete')" class="btn-success btn-sm" spinner />
                <x-button icon="o-arrow-right" label="ارجاع دسته‌ای" wire:click="openBulkModal('forward')" class="btn-warning btn-sm" spinner />
                <x-button icon="o-x-mark" label="لغو انتخاب" wire:click="$set('selectedTickets', [])" class="btn-ghost btn-sm" />
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
        ]" :rows="$tickets" with-pagination>

            @scope('cell_checkbox', $ticket)
            <input type="checkbox"
                class="checkbox checkbox-sm checkbox-primary"
                wire:click="toggleTicketSelection({{ $ticket->id }})"
                @if(in_array($ticket->id, $selectedTickets)) checked @endif />
            @endscope

            @scope('cell_user.person.f_name', $ticket)
            <div class="flex flex-col">
                <span class="font-bold text-sm">{{ $ticket->user->person?->f_name }} {{ $ticket->user->person?->l_name }}</span>
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
                <x-button icon="o-check" wire:click="acceptTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-success" spinner />
                <x-button icon="o-x-mark" wire:click="rejectTicket({{ $ticket->id }})"
                    wire:confirm="آیا مطمئن هستید؟" class="btn-ghost btn-sm text-error" spinner />
                @endif

                <x-button icon="o-eye" wire:click="showTicket({{ $ticket->id }})" class="btn-ghost btn-sm text-info" spinner />

                @if($ticket->status !== 'completed' && $ticket->status !== 'rejected' && $ticket->unit_id == auth()->user()->person?->u_id)
                <x-button icon="o-arrow-path" wire:click="openCompletionModal({{ $ticket->id }})" class="btn-ghost btn-sm text-primary" spinner />
                @endif
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
                                <span class="font-bold text-xs">{{ $activity->user->person?->f_name }} {{ $activity->user->person?->l_name }}</span>
                                <span class="text-[10px] opacity-50 font-mono">{{ jdate($activity->created_at)->format('H:i - Y/m/d') }}</span>
                            </div>
                            <p class="text-xs opacity-70">{{ $activity->description }}</p>
                            @if($activity->attachments->count() > 0)
                            <div class="flex gap-1 mt-2">
                                @foreach($activity->attachments as $actFile)
                                <x-button icon="o-arrow-down-tray" link="{{ asset('storage/' . $actFile->file_path) }}"
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
                    <button type="button" wire:click="selectTargetUnit({{ $u->id }}, '{{ $u->name }}')"
                        class="w-full text-right px-4 py-2 hover:bg-primary hover:text-white text-sm transition-colors border-b last:border-0">
                        {{ $u->name }}
                    </button>
                    @endforeach
                </div>
                @endif

                @if($this->targetUnitId)
                <div class="mt-2 flex items-center gap-2 text-info text-sm">
                    <x-icon name="o-check-circle" />
                    <span class="font-bold">مقصد: {{ $this->targetUnitName }}</span>
                    <x-button icon="o-x-mark" wire:click="$set('targetUnitId', null)" class="btn-ghost btn-xs" />
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
</div>

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
