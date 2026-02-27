<?php

namespace App\Livewire\Tickets;

use App\Models\Ticket;
use App\Models\Unit;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;
use Livewire\Attributes\Locked;

#[Layout('components.layouts.app')]
#[Locked]
class TicketInbox extends Component
{

    use WithPagination;
    // داخل کلاس حتما این تریت باشد
    use WithFileUploads;

    public $isCompletionModalOpen = false; // برای مدیریت مودال کوچک تایید نهایی
    public $completionNote = '';
    public $completionFiles = [];
    public $search = '';
    public $unitSearch = '';
    public $targetUnitId = null;
    public $targetUnitName = '';
    public $forwardNote = '';
    public $showingTicket = null;
    public $dateFrom = '';
    public $dateTo = '';
    public $currentTab = 'pending'; // تب پیش‌فرض: در انتظار بررسی
    public $showingTicketId;
    public bool $showModal = false; // این خط را اضافه کنید
    public $selectedAssigneeId = null;

    public function updateFilter($viewMode, $statusFilter = 'pending')
    {
        $this->viewMode = $viewMode;
        $this->statusFilter = $statusFilter;

        // همیشه وقتی فیلتر عوض می‌شود، باید به صفحه اول جدول برگردیم
        $this->resetPage();
    }
    public function updatedDateFrom()
    {
        $this->resetPage();
    }

    public function updatedDateTo()
    {
        $this->resetPage();
    }
    // متد برای تغییر تب
    public function setTab($tab)
    {
        $this->currentTab = $tab;
        $this->resetPage(); // برگشت به صفحه اول در صورت استفاده از پجینیشن
    }

    public $viewMode = 'received';
    public $statusFilter = 'pending'; // مقدار پیش‌فرض

    public function closeAllModals()
    {
        // بستن مودال عملیات
        $this->isCompletionModalOpen = false;

        // بستن مودال تاریخچه/جزئیات
        $this->showingTicket = null;
        $this->showingTicketId = null;

        // ریست کردن فیلدها برای استفاده بعدی
        $this->reset([
            'completionNote',
            'completionFiles',
            'targetUnitId',
            'targetUnitName',
            'unitSearch',

        ]);
    }
    public function render()
    {
        $user = auth()->user();
        $units = [];

        if (strlen($this->unitSearch) > 1) {
            $units = Unit::where('name', 'like', '%' . $this->unitSearch . '%')
                ->where('can_receive_tickets', true)
                // اضافه کردن شرط زیر برای حذف واحد فعلی کاربر از لیست جستجو
                ->where('id', '!=', auth()->user()->person?->u_id)
                ->limit(5)
                ->get();
        }

        // لود کردن رابطه‌های مورد نیاز شامل فعالیت‌ها
        $query = Ticket::with(['user', 'unit', 'assignee', 'activities']);

        // --- اصلاح فیلتر بر اساس جهت تیکت ---
        if ($this->viewMode === 'received') {
            // ورودی‌ها: تیکت‌هایی که الان در واحد من هستند
            $query->where('unit_id', $user->person?->u_id);
        } else {
            // ارسالی‌ها:
            $query->where(function ($q) use ($user) {
                // ۱. تیکت‌هایی که من خودم ایجاد کرده‌ام (حتی اگر هنوز در واحد خودم باشد)
                $q->where('user_id', $user->id)
                    // ۲. یا تیکت‌هایی که من روی آن‌ها اقدامی انجام داده‌ام "اما" الان دیگر در واحد من نیستند
                    ->orWhere(function ($subQ) use ($user) {
                        $subQ->whereHas('activities', function ($activityQuery) use ($user) {
                            $activityQuery->where('user_id', $user->id);
                        })
                            ->where('unit_id', '!=', $user->person?->u_id); // تیکت از واحد من خارج شده باشد
                    });
            });
        }

        // --- فیلتر وضعیت‌ها ---
        if ($this->statusFilter === 'pending') {
            $query->whereIn('status', ['created', 'forwarded']);
        } elseif ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        // ... (بقیه کدهای تاریخ و جستجو که داشتی دست نخورده باقی می‌ماند) ...
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
        if ($user->hasRole('admin')) {
            // ادمین کل همه را می‌بیند
        } elseif ($user->hasPermissionTo('manage_unit_tickets')) {
            // مدیر واحد تمام تیکت‌های واحد خودش را می‌بیند
            $query->where('unit_id', $user->person?->u_id);
        } else {
            // کارشناس: تیکت‌هایی که واحدش یکی است و مستقیماً به او ارجاع شده
           
            $query->where('unit_id', $user->person?->u_id)
                ->where(function ($q) use ($user) {
                    $q->where('current_assignee_id', $user->person?->u_id);
                });
        }
        return view('livewire.tickets.ticket-inbox', [
            'tickets' => $query->latest()->paginate(5),
            'units' => $units,

        ]);
    }

    public function switchView($mode)
    {
        $this->viewMode = $mode;
        $this->statusFilter = 'pending'; // همیشه وقتی بین ورودی/ارسالی جابجا می‌شوید، برود روی در انتظار
        $this->resetPage();
    }
    public function showTicket($id)
    {
        // لود کردن رابطه‌ها بر اساس نام‌های درست در مدل‌ها

        $this->showingTicket = Ticket::with(['attachments', 'activities.attachments', 'activities.user', 'user', 'unit'])->findOrFail($id);
        $this->showModal = true; // مودال را فعال کن
    }

    public function closeDetail()
    {
        $this->showingTicket = null;
        $this->reset(['targetUnitId', 'showingTicket', 'targetUnitName', 'unitSearch', 'forwardNote']);
        $this->showModal = false; // مودال را ببند
    }

    public function selectTargetUnit($id, $name)
    {
        $this->targetUnitId = $id;
        $this->targetUnitName = $name;
        $this->unitSearch = '';
    }

    public function forward()
    {
        $this->validate([
            'targetUnitId' => 'required|exists:units,id',
            'forwardNote' => 'nullable|string|max:500',
        ]);

        try {
            \DB::transaction(function () {
                // ثبت در دیتابیس
                $this->showingTicket->update([
                    'unit_id' => $this->targetUnitId,
                    'status' => 'forwarded',
                    'current_assignee_id' => null // چون به واحد جدید رفته، هنوز کسی مسئولش نیست
                ]);

                // ثبت فعالیت در تاریخچه
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

    public function acceptTicket($ticketId)
    {
        $ticket = Ticket::findOrFail($ticketId);
        DB::transaction(function () use ($ticket) {
            $ticket->update([
                'status' => 'accepted',
                'current_assignee_id' => auth()->id(), // آی‌دی کاربر لاگین شده
                'accepted_at' => now(),
            ]);

            $ticket->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'accepted',
                'description' => 'تیکت توسط کارشناس تایید شد و مسئولیت آن پذیرفته شد.'
            ]);
        });

        $this->dispatch('swal', ['title' => 'تیکت پذیرفته شد', 'icon' => 'success']);
        $this->closeDetail();
    }

    public function rejectTicket($ticketId)
    {
        try {
            $ticket = Ticket::where('unit_id', auth()->user()->person?->u_id)->findOrFail($ticketId);

            \DB::transaction(function () use ($ticket) {
                $ticket->update([
                    'status' => 'rejected',
                    'current_assignee_id' => auth()->id(), // کسی که تیکت را رد کرده
                ]);

                // ثبت در تاریخچه فعالیت‌ها
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
    // ۲. باز کردن مودال کوچک برای اتمام کار
    public function openCompletionModal($id)
    {
        $this->showingTicketId = $id;
        // برای اطمینان از اینکه آبجکت تیکت هم در مودال در دسترس باشد
        $this->showingTicket = Ticket::find($id);
        $this->isCompletionModalOpen = true;
    }

    // ۳. متد نهایی تکمیل تیکت
    public function submitAction($id = null)
    {
        $finalId = $id ?? $this->showingTicketId;
        $ticket = Ticket::findOrFail($finalId);

        // بررسی اینکه آیا تیکت قابلیت مختومه شدن دارد یا خیر
        // تیکت زمانی مختومه می‌شود که نه واحد مقصد انتخاب شده باشد و نه شخص مقصد
        if ($ticket->status !== 'accepted' && !$this->targetUnitId && !$this->selectedAssigneeId) {
            $this->addError('completionNote', 'تیکت تایید نشده را نمی‌توان مختومه کرد. ابتدا ارجاع دهید یا تایید کنید.');
            return;
        }

        // تعیین نوع اکشن: اگر هر نوع ارجاعی (واحد یا شخص) پر باشد، اکشن ما forwarded است
        $isForwarding = ($this->targetUnitId || $this->selectedAssigneeId);
        $actionType = $isForwarding ? 'forwarded' : 'completed';

        $this->validate([
            'completionNote' => $actionType === 'completed' ? 'required|min:5' : 'nullable|max:1000',
            'completionFiles' => 'nullable|array|max:5',
            'completionFiles.*' => 'file|mimes:jpg,jpeg,png,pdf,zip,rar,docx,xlsx|max:5120',
        ]);

        try {
            DB::beginTransaction();

            if ($isForwarding) {
                if ($this->targetUnitId) {
                    // سناریو ۱: ارجاع بین‌واحدی
                    $ticket->update([
                        'unit_id' => $this->targetUnitId,
                        'status' => 'forwarded',
                        'current_assignee_id' => null, // تیکت به صف عمومی واحد مقصد می‌رود
                    ]);
                    $description = "ارجاع به واحد: {$this->targetUnitName}";
                } else {
                    // سناریو ۲: ارجاع درون‌واحدی (به شخص)
                    // پیدا کردن کاربر به همراه اطلاعات شخصی‌اش
                    $assignee = \App\Models\User::with('person')->find($this->selectedAssigneeId);

                    // گرفتن نام و نام خانوادگی از مدل Person
                    $fullName = $assignee->person
                        ? $assignee->person->f_name . ' ' . $assignee->person->l_name
                        : 'کارشناس نامشخص';
                    $ticket->update([
                        'current_assignee_id' => $this->selectedAssigneeId,
                        'status' => 'accepted', // چون به شخص ارجاع شده، وضعیت پذیرفته شده می‌گیرد
                    ]);
                    $description = "ارجاع به کارشناس: {$fullName}";
                }

                if ($this->completionNote) {
                    $description .= " | توضیحات: {$this->completionNote}";
                }
                $message = "تیکت با موفقیت ارجاع داده شد.";
            } else {
                // سناریو ۳: مختومه کردن
                $ticket->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                $description = "تیکت مختومه شد. گزارش نهایی: {$this->completionNote}";
                $message = "تیکت با موفقیت مختومه و بسته شد.";
            }

            // ۲. ثبت فعالیت جدید
            $newActivity = $ticket->activities()->create([
                'user_id' => auth()->id(),
                'action' => $actionType, // همان forwarded یا completed
                'description' => $description,
                'to_unit_id' => $this->targetUnitId ?? $ticket->unit_id,
            ]);

            // ۳. ثبت فایل‌ها (بدون تغییر نسبت به کد خودت)
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

            $this->reset(['isCompletionModalOpen', 'showingTicket', 'completionNote', 'completionFiles', 'targetUnitId', 'targetUnitName', 'unitSearch', 'selectedAssigneeId']);

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
    public function removeFile($index)
    {
        array_splice($this->completionFiles, $index, 1);
    }

    // وقتی واحد مقصد تغییر می‌کند، انتخاب کارشناس ریست شود
    public function updatedTargetUnitId($value)
    {
        if ($value) {
            $this->selectedAssigneeId = null;
        }
    }

    // وقتی کارشناس انتخاب می‌شود، انتخاب واحد ریست شود
    public function updatedSelectedAssigneeId($value)
    {
        if ($value) {
            $this->targetUnitId = null;
        }
    }

    // لیست کاربران هم‌واحدی برای ارجاع داخلی
    public function getMyTeamProperty()
    {
        // ابتدا u_id کاربر فعلی را از رابطه person می‌گیریم
        $myUnitId = auth()->user()->person?->u_id;

        if (!$myUnitId) {
            return collect();
        }

        // حالا کاربرانی را پیدا می‌کنیم که در جدول person، همان u_id را دارند
        return \App\Models\User::whereHas('person', function ($query) use ($myUnitId) {
            $query->where('u_id', $myUnitId);
        })
            ->where('id', '!=', auth()->id()) // خودش در لیست نباشد
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'full_name' => $user->person ? $user->person->f_name . ' ' . $user->person->l_name : $user->name
                ];
            });
    }
}
