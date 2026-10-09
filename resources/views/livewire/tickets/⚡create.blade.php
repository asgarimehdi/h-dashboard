<?php

use App\Models\{Unit, Todo};
use App\Models\Ticket;
use App\Rules\{AccessibleTodo, TicketTargetUnit};
use App\Services\AccessService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Mary\Traits\Toast;

new class extends Component
{
    use WithFileUploads;
    use Toast;

    public bool $showHelpModal = false;

    public string $search = '';
    public ?int $unit_id = null;
    public bool $showDropdown = false;
    public string $subject = '';
    public string $content = '';
    public string $priority = 'normal';
    public array $files = [];
    public ?int $task_id = null;
    public $todos = [];
    public array $units = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $units = [];

        if (mb_strlen($this->search) >= 2) {
            $userUnitId = auth()->user()->person?->u_id;

            $query = Unit::where('can_receive_tickets', true)->where('is_active', true);

            if ($userUnitId) {
                $query->where('id', '!=', $userUnitId);
            }

            $units = $query->where('name', 'like', '%' . $this->search . '%')->take(5)->get()->toArray();
        }

        // وظایف باز در اسکوپ سازنده — نه فقط واحد جاری (تصمیم #847، گزینهٔ ج).
        // `->accessible('unit_id')` یک `whereIn` بدون شرط است، پس اسکوپ خالی
        // به `0 = 1` تبدیل می‌شود و هیچ ردیف بی‌واحدی لو نمی‌رود؛ برخلاف
        // `where('unit_id', $currentUnitId)` که برای کاربر بدون واحد
        // به `unit_id IS NULL` کامپایل می‌شد و همهٔ todoهای orphan را
        // برمی‌گرداند.
        // Explicit `whereIn` rather than the `accessible()` scope: the scope lives on
        // a trait, so PHPStan cannot resolve `Builder::accessible()`, and this
        // form is also what keeps the empty scope failing closed (`0 = 1`)
        // instead of dropping the predicate. See `AccessibleTodo` for the same
        // reasoning on the write side.
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->todos = Todo::query()
            ->whereIn('unit_id', $accessibleIds)
            ->where('is_completed', false)
            ->take(50)
            ->get()
            ->toArray();

        $this->units = $units;
    }

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
        $this->loadData();
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
                new TicketTargetUnit,
                function ($attribute, $value, $fail) {
                    if ($value == auth()->user()->person?->u_id) {
                        $fail('شما نمی‌توانید به واحد خودتان تیکت ارسال کنید.');
                    }
                },
            ],
            'subject' => 'required|string|min:5|max:255',
            'content' => 'required|string|min:10',
            // #847: priority was also unvalidated, so the web form stored
            // values (`medium`, `high`) the CHECK constraint allows but the
            // picker and the API both refuse — and anything outside the set
            // raised SQLSTATE[23514]. Same three values as `TicketController`.
            'priority' => 'required|in:low,normal,urgent',
            // #847: the last unvalidated input on this form. Existence and
            // scope are both the rule's job, so a foreign todo and a missing
            // id are validation errors rather than a FK violation.
            'task_id' => ['nullable', new AccessibleTodo],
            // Issue #873: the allowlist and the size cap existed only in
            // `updatedFiles()`, which runs on the upload hook. That error is a
            // per-request artefact — `saveTicket()` is a separate POST with a
            // fresh error bag — so it never guarded the write loop below, and
            // any holder of `create_ticket` (the lowest role) could store a
            // file of any type, up to Livewire's 12MB temporary-upload
            // ceiling, into `public/storage/attachments`. Validate in the
            // method that writes, same shape as `⚡inbox.blade.php:676-677`
            // (`submitAction()`). The allowlist and the 5120KB cap are the
            // ones this component already documents in `updatedFiles()` —
            // deliberately NOT widened to docx/xlsx (out of scope for #873),
            // and deliberately NOT pushed into `config/livewire.php`, where a
            // global allowlist would break the two import components that
            // legitimately take `mimes:xlsx,xls,csv`.
            'files' => 'nullable|array|max:5',
            'files.*' => 'file|mimes:jpg,jpeg,png,pdf,zip,rar|max:5120',
        ]);

        $ticketCode = 'TK-' . strtoupper(Str::random(8));

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

        // اگر وظیفه‌ای انتخاب نشده، وظیفه جدید ایجاد کن.
        // #847 (تصمیم محصول، گزینهٔ ج): وظیفه باید در اسکوپ سازنده باشد.
        // رفتار قبلی همیشه واحد مقصد را می‌نوشت، حتی وقتی آن واحد در
        // `accessibleUnitIds()` سازنده نبود — یعنی تیکت به todoای در
        // واحدی لینک می‌شد که خودِ آن todo هم بلافاصله از اسکوپ خواننده
        // خارج می‌شد و در read-side مخفی می‌شد.
        if (! $this->task_id) {
            $destinationInScope = in_array(
                $this->unit_id,
                app(\App\Services\AccessService::class)->accessibleUnitIds(),
                true
            );

            $todo = Todo::create([
                'title' => $this->subject,
                'start_at' => now(),
                'end_at' => now()->addWeek(),
                'is_completed' => false,
                'unit_id' => $destinationInScope ? $this->unit_id : session('current_unit_id', auth()->user()->person?->u_id),
            ]);
            $ticket->update(['task_id' => $todo->id]);
        }

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
            timeout: 5000,
            redirectTo: null
        );

        $this->reset(['subject', 'content', 'priority', 'files', 'unit_id', 'search', 'task_id']);
        $this->showDropdown = false;
    }

};
?>

<div>
    <x-header title="ایجاد تیکت جدید" separator progress-indicator>
            <x-slot:actions>
                <x-help:button section="tickets" wireModel="showHelpModal" />
                <x-theme-selector />
            </x-slot:actions>
        </x-header>

        <x-help:modal wireModel="showHelpModal" />

    <x-card shadow>
        <x-errors :only="['unit_id', 'subject', 'content', 'files', 'task_id', 'priority']" title="خطا در ثبت تیکت" />
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
                        wire:click="selectUnit({{ $unit['id'] }}, @js($unit['name']))"
                        class="p-3 text-sm hover:bg-primary hover:text-white cursor-pointer transition-colors border-b border-base-200 last:border-0">
                        {{ $unit['name'] }}
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

            @if(count($todos) > 0)
            <div class="col-span-2">
                <x-select
                    label="وظیفه مرتبط (اختیاری)"
                    wire:model="task_id"
                    placeholder="انتخاب کنید..."
                    icon="o-calendar-days"
                    :options="array_map(fn($t) => ['id' => $t['id'], 'name' => $t['title'] . ' (' . jdate($t['start_at'])->format('Y/m/d') . ')'], $todos)"
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
