<?php

use App\Models\Todo; // فرض بر این است که مدل Todo را دارید
use Livewire\Volt\Component; // یا اگر از Volt استفاده نمی‌کنید، ساختار ناشناس لایووایر
use Livewire\Component as BaseComponent;
use Mary\Traits\Toast;
use Carbon\Carbon;

return new class extends BaseComponent {
    use Toast;

    public bool $modal = false;
    public string $title = '';
    public $start_at;
    public $end_at;
    public ?int $editingId = null;

    // دریافت رویدادها برای نمایش در تقویم
    public function getEvents()
    {
        return Todo::all()->map(function ($todo) {
            return [
                'id' => $todo->id,
                'title' => $todo->title,
                'start' => $todo->start_at,
                'end' => $todo->end_at,
                'color' => $todo->is_completed ? '#10b981' : '#3b82f6',
                'allDay' => false
            ];
        })->toArray();
    }

    // باز کردن مدال برای ثبت تسک جدید (فراخوانی از سمت JS)
    public function openCreateModal($start, $end)
    {
        $this->reset(['title', 'editingId']);
        $this->start_at = $start;
        $this->end_at = $end;
        $this->modal = true;
    }

    // باز کردن مدال برای ویرایش
    public function editEvent($id)
    {
        $todo = Todo::find($id);
        $this->editingId = $id;
        $this->title = $todo->title;
        $this->start_at = $todo->start_at;
        $this->end_at = $todo->end_at;
        $this->modal = true;
    }

    // ذخیره تسک
    public function save(): void
    {
        $this->validate([
            'title' => 'required|min:3',
            'start_at' => 'required',
        ]);

        Todo::updateOrCreate(
            ['id' => $this->editingId],
            [
                'title' => $this->title,
                'start_at' => Carbon::parse($this->start_at),
                'end_at' => $this->end_at ? Carbon::parse($this->end_at) : null,
            ]
        );

        $this->success('با موفقیت ذخیره شد');
        $this->modal = false;
        
        // ارسال رویداد برای بروزرسانی تقویم در سمت کلاینت
        $this->dispatch('calendar-updated');
    }

    public function delete(): void
    {
        if ($this->editingId) {
            Todo::find($this->editingId)->delete();
            $this->success('تسک حذف شد');
            $this->modal = false;
            $this->dispatch('calendar-updated');
        }
    }

    public function with(): array
    {
        return [
            'events' => $this->getEvents(),
        ];
    }
}; ?>

<div>
    <!-- هدر مشابه بقیه صفحات شما -->
    <x-header title="تقویم کارها (Todo)" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
            <x-button icon="o-plus" label="تسک جدید" class="btn-primary" @click="$wire.modal = true" />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        {{-- بخش تقویم با رعایت عدم تداخل لایووایر --}}
        <div wire:ignore>
            <div id="calendar" class="min-h-[600px]"></div>
        </div>
    </x-card>

    <!-- مدال MaryUI برای فرم -->
    <x-modal wire:model="modal" title="جزئیات تسک" separator>
        <x-form wire:submit="save">
            <x-input label="عنوان فعالیت" wire:model="title" placeholder="مثلاً: جلسه فنی" />
            
            <div class="grid grid-cols-2 gap-4">
                <x-input label="شروع" wire:model="start_at" type="datetime-local" />
                <x-input label="پایان" wire:model="end_at" type="datetime-local" />
            </div>

            <x-slot:actions>
                @if($editingId)
                    <x-button label="حذف" icon="o-trash" class="btn-error" wire:click="delete" wire:confirm="مطمئنی؟" />
                @endif
                <x-button label="لغو" @click="$wire.modal = false" />
                <x-button label="ذخیره" icon="o-check" class="btn-primary" type="submit" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    {{-- اسکریپت‌های مورد نیاز --}}
    @assets
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    @endassets

    <script>
        document.addEventListener('livewire:init', () => {
            const calendarEl = document.getElementById('calendar');
            
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fa', // فعال‌سازی تقویم شمسی به صورت خودکار
                direction: 'rtl',
                firstDay: 6, // شروع هفته از شنبه
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                selectable: true,
                editable: true,
                events: @json($events),

                // انتخاب بازه زمانی (مثل گوگل کلندر)
                select: function(info) {
                    @this.openCreateModal(info.startStr, info.endStr);
                },

                // کلیک روی یک تسک موجود
                eventClick: function(info) {
                    @this.editEvent(info.event.id);
                },

                // درگ اند دراپ (بروزرسانی زمان)
                eventDrop: function(info) {
                    // می‌توانید اینجا متدی برای آپدیت سریع در دیتابیس صدا بزنید
                    @this.openCreateModal(info.event.startStr, info.event.endStr); 
                    // یا مستقیم ذخیره کنید
                }
            });

            calendar.render();

            // گوش دادن به رویداد لایووایر برای رفرش کردن تقویم
            Livewire.on('calendar-updated', () => {
                location.reload(); // ساده‌ترین راه برای رفرش دیتای تقویم
            });
        });
    </script>

    <style>
        /* شخصی‌سازی استایل برای هماهنگی با MaryUI (DaisyUI) */
        .fc { font-family: inherit; }
        .fc-col-header-cell { @apply bg-base-200 py-2; }
        .fc-btn { @apply btn btn-sm; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: oklch(var(--b3)); }
        .fc-event { cursor: pointer; border-radius: 4px; padding: 2px; }
    </style>
</div>