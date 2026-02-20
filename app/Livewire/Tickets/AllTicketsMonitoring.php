<?php

namespace App\Livewire\Tickets;

use App\Models\Ticket;
use App\Models\Unit;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.app')]
class AllTicketsMonitoring extends Component
{
    use WithPagination;

    public $search = '';
    public $statusFilter = 'all';
    public $selectedUnitId = null;
    public $unitSearch = '';
    public $showingTicket = null;
    public $dateFrom = '';
    public $dateTo = '';

    // متدهای بروزرسانی تاریخ برای ریست کردن صفحه‌بندی
    public function updatedDateFrom() { $this->resetPage(); }
    public function updatedDateTo() { $this->resetPage(); }

    public function selectUnitForFilter($id)
    {
        $this->selectedUnitId = $id;
        $this->unitSearch = '';
        $this->resetPage();
    }

    public function render()
    {
        // جستجوی واحدها برای فیلتر بالای صفحه
        $units = [];
        if (strlen($this->unitSearch) > 1) {
            $units = Unit::where('name', 'like', '%' . $this->unitSearch . '%')
                ->where('can_receive_tickets', true)
                ->limit(10)->get();
        }

        $query = Ticket::with(['user', 'unit', 'assignee']);

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

        return view('livewire.tickets.all-tickets-monitoring', [
            'tickets' => $query->latest()->paginate(15),
            'filterUnits' => $units,
            'currentUnit' => $this->selectedUnitId ? Unit::find($this->selectedUnitId) : null
        ]);
    }

    public function showTicket($id)
    {
        // لود کردن تیکت به همراه فایل‌های متصل به فعالیت‌ها
        $this->showingTicket = Ticket::with([
            'user', 
            'unit', 
            'attachments', 
            'activities.user', 
            'activities.attachments' // بسیار مهم برای نمایش صحیح فایل‌ها در ردیف خودشان
        ])->findOrFail($id);
    }

    public function closeDetail()
    {
        $this->showingTicket = null;
    }
}