<?php

namespace App\Livewire\Tickets;

use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\DB;

#[Layout('components.layouts.app')]
class TicketInbox extends Component
{
    use WithPagination, WithFileUploads;

    // متغیرهای وضعیت
    public $viewMode = 'received'; // دریافتی، ارسالی، ارجاعی (in_progress)
    public $statusFilter = 'pending'; 

    public $dateFrom = ''; 
    public $dateTo = '';
    public $search = '';

    // متغیرهای مودال و عملیات
    public bool $showModal = false;
    public bool $isCompletionModalOpen = false;
    public $showingTicket = null;
    public $showingTicketId = null;

    // فیلدهای فرم ارجاع و تکمیل
    public $completionNote = '';
    public $completionFiles = [];
    public $unitSearch = '';
    public $targetUnitId = null;
    public $targetUnitName = '';
    public $selectedAssigneeId = null;
    public $forwardNote = '';

    // متد هوشمند تغییر تب و فیلتر
  public function switchTab($view, $status = 'all')
{
    $this->viewMode = $view;
    $this->statusFilter = $status;
    $this->resetPage(); // برای اینکه از صفحه ۱ شروع کند
}

public function render()
{
    $isManager = auth()->user()->hasAnyRole(['admin', 'unit_manager']);
    $user = auth()->user();
    $myId = $user->id;
    $myUnitId = $user->person?->u_id;

    $query = Ticket::query()->with(['user.person', 'unit', 'assignee.person']);

    // ۱. منطق جداسازی تب‌ها
    if ($this->viewMode === 'sent') {
        $query->where('user_id', $myId);
    } 
   elseif ($this->viewMode === 'received') {
    $query->where('unit_id', $myUnitId)
          ->where(function($q) use ($myId, $isManager) {
              // ۱. کارشناس یا مدیر، تیکت‌هایی که مستقیماً به نام خودشان هست را می‌بینند
              $q->where('current_assignee_id', $myId);
              
              // ۲. فقط مدیر واحد اجازه دارد تیکت‌های بدون صاحب (در سطح واحد) را ببیند
              if ($isManager) {
                  $q->orWhereNull('current_assignee_id');
              }
          });
}
    elseif ($this->viewMode === 'in_progress') {
        // تب ارجاعی: هر تیکتی که من در فعالیت‌هایش ردپایی دارم
        $query->whereHas('activities', function ($sub) use ($myId) {
            $sub->where('user_id', $myId);
        });
    }

    // ۲. فیلتر وضعیت (اصلاح شده برای نمایش تیکت‌های ارجاع شده در حالت 'جدید')
    if ($this->statusFilter && $this->statusFilter !== 'all') {
        if ($this->statusFilter === 'pending') {
            // تیکت شماره ۱ وضعیتش forwarded است؛ پس باید در لیست 'در انتظار' باشد
            $query->whereIn('status', ['created', 'forwarded', 'assigned', 'accepted']);
        } else {
            $query->where('status', $this->statusFilter);
        }
    }

    // ۳. فیلتر جستجو و تاریخ (با رعایت شرط پر بودن)
    if (filled($this->search)) {
        $query->where(function($q) {
            $q->where('subject', 'like', '%' . $this->search . '%')
              ->orWhere('ticket_code', 'like', '%' . $this->search . '%');
        });
    }

    if (filled($this->dateFrom)) {
        try {
            $date = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateFrom)->toCarbon()->startOfDay();
            $query->where('created_at', '>=', $date);
        } catch (\Exception $e) {}
    }

    if (filled($this->dateTo)) {
        try {
            $date = \Morilog\Jalali\Jalalian::fromFormat('Y/m/d', $this->dateTo)->toCarbon()->endOfDay();
            $query->where('created_at', '<=', $date);
        } catch (\Exception $e) {}
    }

    return view('livewire.tickets.ticket-inbox', [
        'tickets' => $query->latest('updated_at')->paginate(10),
        'units' => strlen($this->unitSearch) > 1 
            ? \App\Models\Unit::where('name', 'like', "%{$this->unitSearch}%")
                ->limit(5)
                ->get() 
            : []
    ]);
}
    // --- مدیریت نمایش و مودال‌ها ---

    public function showTicket($id)
    {
        $ticket = Ticket::findOrFail($id);
        
        // علامت‌گذاری به عنوان خوانده شده فقط در تب دریافتی
        if ($this->viewMode === 'received' && is_null($ticket->read_at)) {
            $ticket->update(['read_at' => now()]);
        }

        $this->showingTicket = $ticket;
        $this->showingTicketId = $id;
        $this->showModal = true;
    }

    public function openCompletionModal($id)
    {
        $this->showingTicketId = $id;
        $this->showingTicket = Ticket::find($id);
        $this->isCompletionModalOpen = true;
    }

    public function closeAllModals()
    {
        $this->showModal = false;
        $this->isCompletionModalOpen = false;
        $this->reset([
            'completionNote', 'completionFiles', 'targetUnitId', 
            'targetUnitName', 'unitSearch', 'selectedAssigneeId', 
            'forwardNote', 'showingTicket', 'showingTicketId'
        ]);
    }

    // --- عملیات تیکت ---

    public function acceptTicket($ticketId)
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
                'description' => 'تیکت تایید و مسئولیت آن پذیرفته شد.'
            ]);
        });
        $this->dispatch('swal', ['title' => 'تیکت پذیرفته شد', 'icon' => 'success']);
        $this->closeAllModals();
    }

    public function submitAction()
    {
        $ticket = Ticket::findOrFail($this->showingTicketId);
        $isForwarding = ($this->targetUnitId || $this->selectedAssigneeId);
        $actionType = $isForwarding ? 'forwarded' : 'completed';

        $this->validate([
            'completionNote' => $actionType === 'completed' ? 'required|min:5' : 'nullable|max:1000',
            'completionFiles.*' => 'nullable|file|max:5120',
        ]);

        try {
            DB::beginTransaction();

            if ($isForwarding) {
                $this->handleForwarding($ticket);
                $message = "تیکت با موفقیت ارجاع شد.";
            } else {
                $this->handleCompletion($ticket);
                $message = "تیکت مختومه شد.";
            }

            DB::commit();
            $this->dispatch('swal', ['title' => 'عملیات موفق', 'text' => $message, 'icon' => 'success']);
            $this->closeAllModals();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->addError('completionNote', 'خطا: ' . $e->getMessage());
        }
    }

    private function handleForwarding($ticket)
    {
        if ($this->targetUnitId) {
            $ticket->update([
                'unit_id' => $this->targetUnitId,
                'status' => 'forwarded',
                'current_assignee_id' => null,
                'read_at' => null
            ]);
            $desc = "ارجاع به واحد: {$this->targetUnitName}";
        } else {
            $assignee = User::with('person')->find($this->selectedAssigneeId);
            $fullName = $assignee->person ? $assignee->person->f_name . ' ' . $assignee->person->l_name : $assignee->name;
            $ticket->update([
                'current_assignee_id' => $this->selectedAssigneeId,
                'status' => 'accepted',
                'read_at' => null
            ]);
            $desc = "ارجاع به کارشناس: {$fullName}";
        }

        $activity = $ticket->activities()->create([
            'user_id' => auth()->id(),
            'action' => 'forwarded',
            'description' => $desc . ($this->completionNote ? " | " . $this->completionNote : ""),
            'to_unit_id' => $this->targetUnitId ?? $ticket->unit_id,
        ]);

        $this->uploadAttachments($ticket, $activity);
    }

    private function handleCompletion($ticket)
    {
        $ticket->update(['status' => 'completed', 'completed_at' => now()]);
        $activity = $ticket->activities()->create([
            'user_id' => auth()->id(),
            'action' => 'completed',
            'description' => "مختومه شد: " . $this->completionNote,
        ]);
        $this->uploadAttachments($ticket, $activity);
    }

    private function uploadAttachments($ticket, $activity)
    {
        foreach ($this->completionFiles as $file) {
            $path = $file->store('attachments', 'public');
            $ticket->attachments()->create([
                'user_id' => auth()->id(),
                'activity_id' => $activity->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);
        }
    }

    // --- Computed Properties ---

    public function getMyTeamProperty()
    {
        $myUnitId = auth()->user()->person?->u_id;
        if (!$myUnitId) return collect();

        return User::whereHas('person', fn($q) => $q->where('u_id', $myUnitId))
            ->where('id', '!=', auth()->id())
            ->get();
    }

    public function selectTargetUnit($id, $name)
    {
        $this->targetUnitId = $id;
        $this->targetUnitName = $name;
        $this->selectedAssigneeId = null;
        $this->unitSearch = '';
    }
}