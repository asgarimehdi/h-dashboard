<?php

use App\Models\{Unit, Todo};
use App\Models\Ticket;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Mary\Traits\Toast;

new class extends Component
{
    use WithFileUploads;
    use Toast;

    public string $search = '';
    public ?int $unit_id = null;
    public bool $showDropdown = false;
    public string $subject = '';
    public string $content = '';
    public string $priority = 'normal';
    public array $files = [];
    public ?int $task_id = null;
    public $todos = [];

    public function selectUnit($id, $name): void
    {
        $this->unit_id = $id;
        $this->search = $name;
        $this->showDropdown = false;
    }

    public function updatedSearch(): void
    {
        $this->unit_id = null;
        $this->showDropdown = true;
    }

    public function updatedFiles(): void
    {
        $this->resetErrorBag('files');
        if (count($this->files) > 5) {
            $this->addError('files', 'حداکثر ۵ فایل مجاز است.');
            $this->files = [];
            return;
        }
        $this->validate(['files.*' => 'mimes:jpg,jpeg,png,pdf,zip,rar|max:5120']);
    }

    public function removeFile($index): void
    {
        if (isset($this->files[$index])) {
            unset($this->files[$index]);
            $this->files = array_values($this->files);
        }
    }

    public function resetForm(): void
    {
        $this->reset(['subject', 'content', 'priority', 'files', 'unit_id', 'search', 'task_id']);
        $this->showDropdown = false;
    }

    public function saveTicket(): void
    {
        $this->validate([
            'unit_id' => [
                'required',
                'exists:units,id',
                function ($attribute, $value, $fail) {
                    if ($value == auth()->user()->person?->u_id) {
                        $fail('شما نمی‌توانید به واحد خودتان تیکت ارسال کنید.');
                    }
                },
            ],
            'subject' => 'required|string|min:5|max:255',
            'content' => 'required|string|min:10',
        ]);

        $ticketCode = 'TK-' . strtoupper(substr(uniqid(), -6));

        $ticket = Ticket::create([
            'ticket_code' => $ticketCode,
            'user_id' => auth()->id(),
            'unit_id' => $this->unit_id,
            'subject' => $this->subject,
            'content' => $this->content,
            'priority' => $this->priority,
            'status' => 'created',
            'current_assignee_id' => null,
            'task_id' => $this->task_id,
        ]);

        // ثبت فعالیت
        \App\Services\ActivityLogService::created(
            $ticket,
            "ایجاد تیکت {$ticketCode} به واحد " . $ticket->unit->name
        );

        // ارسال اعلان به کاربران واحد مقصد
        \App\Services\NotificationService::notifyUnit(
            $this->unit_id,
            'ticket_created',
            'تیکت جدید دریافت شد',
            "تیکت #{$ticketCode} با موضوع: {$this->subject}",
            '/tickets/inbox'
        );

        $initialActivity = $ticket->activities()->create([
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => 'تیکت ایجاد شد و به واحد ' . $ticket->unit->name . ' اختصاص یافت.',
            'to_unit_id' => $this->unit_id,
            'is_internal' => false,
        ]);

        if ($this->files) {
            foreach ($this->files as $file) {
                $path = $file->store('attachments', 'public');
                $ticket->attachments()->create([
                    'user_id' => auth()->id(),
                    'activity_id' => $initialActivity->id,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                ]);
            }
        }

        $this->success(
            title: 'تیکت با موفقیت ثبت شد',
            description: "کد پیگیری شما: {$ticketCode}",
            position: 'toast-top toast-left',
            icon: 'o-check-circle',
            css: 'alert-success font-bold',
            timeout: 0,
            redirectTo: null
        );

        $this->reset(['subject', 'content', 'priority', 'files', 'unit_id', 'search', 'task_id']);
        $this->showDropdown = false;
    }

    public function render()
    {
        $units = [];
        $todos = [];

        if (strlen($this->search) >= 2) {
            $userUnitId = auth()->user()->person?->u_id;

            $query = Unit::where('can_receive_tickets', true)->where('is_active', true);

            if ($userUnitId) {
                $query->where('id', '!=', $userUnitId);
            }

            $units = $query->where('name', 'like', '%' . $this->search . '%')->take(5)->get();
        }

        // لود وظایف انجام‌نشده واحد فعلی
        $currentUnitId = session('current_unit_id', auth()->user()->person?->u_id);
        $todos = Todo::where('unit_id', $currentUnitId)
            ->where('is_completed', false)
            ->get();

        return $this->view(compact('units', 'todos'));
    }
};
?>

<div>
    <x-header title="ثبت تیکت جدید" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <x-form wire:submit="saveTicket" class="grid grid-cols-2 gap-4">
            <div class="relative">
                <x-input
                    label="واحد گیرنده"
                    placeholder="جستجوی واحد..."
                    wire:model.live.debounce.300ms="search"
                    icon="o-magnifying-glass" />

                @if($this->showDropdown && !empty($units))
                <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-lg shadow-xl max-h-52 overflow-auto">
                    @foreach($units as $unit)
                    <div
                        wire:click="selectUnit({{ $unit->id }}, '{{ $unit->name }}')"
                        class="p-3 text-sm hover:bg-primary hover:text-white cursor-pointer transition-colors border-b border-base-200 last:border-0">
                        {{ $unit->name }}
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            <x-select
                label="سطح فوریت"
                icon="o-bolt"
                :options="[
                    ['id' => 'low', 'name' => 'عادی'],
                    ['id' => 'normal', 'name' => 'متوسط'],
                    ['id' => 'urgent', 'name' => 'فوری']
                ]"
                wire:model="priority" />

            <x-input
                label="موضوع تیکت"
                placeholder="خلاصه‌ای از درخواست شما..."
                wire:model="subject"
                icon="o-pencil-square" />

            <div class="col-span-2">
                <x-textarea
                    label="شرح درخواست"
                    wire:model="content"
                    placeholder="جزئیات مشکل خود را اینجا بنویسید..."
                    rows="4" />
            </div>

            @if($todos->count() > 0)
            <div class="col-span-2">
                <x-select
                    label="وظیفه مرتبط (اختیاری)"
                    wire:model="task_id"
                    placeholder="انتخاب کنید..."
                    icon="o-calendar-days"
                    :options="$todos->map(fn($t) => ['id' => $t->id, 'name' => $t->title . ' (' . jdate($t->start_at)->format('Y/m/d') . ')'])->toArray()"
                    :clearable="true"
                />
                <p class="text-xs text-base-content/50 mt-1">در صورت انتخاب، این تیکت به وظیفه مرتبط می‌شود.</p>
            </div>
            @endif

            <div class="col-span-2">
                <x-file
                    wire:model="files"
                    label="پیوست مستندات"
                    multiple
                    icon="o-cloud-arrow-up"
                    accept="image/*,application/pdf" />

                @if(count($this->files) > 0)
                <div class="mt-2 space-y-1">
                    @foreach($this->files as $index => $file)
                    <div class="flex items-center justify-between bg-base-200/50 p-2 rounded-lg">
                        <div class="flex items-center gap-2 overflow-hidden">
                            <x-icon name="o-paper-clip" class="w-4 h-4 text-gray-400" />
                            <span class="text-xs truncate">{{ $file->getClientOriginalName() }}</span>
                        </div>
                        <x-button icon="o-x-mark" wire:click="removeFile({{ $index }})" class="btn-ghost btn-xs text-error" />
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            <div class="col-span-2 flex justify-end gap-4">
                <x-button type="submit" label="ارسال نهایی" icon="o-paper-airplane" class="btn-primary" spinner />
                <x-button label="لغو" wire:click="resetForm" icon="o-x-mark" class="btn-ghost" />
            </div>
        </x-form>
    </x-card>
</div>
