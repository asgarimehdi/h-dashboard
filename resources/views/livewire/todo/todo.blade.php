<?php

use App\Models\{Todo, User};
use Livewire\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

return new class extends Component {
    use Toast;

    public bool $modal = false;
    public string $title = '';
    public $start_at;
    public $end_at;
    public ?int $editingId = null;
    public bool $is_completed = false;
    public array $user_ids = [];
    public ?int $unit_id = null;

    public $start_date_picker;
    public $start_time_picker;
    public $end_date_picker;
    public $end_time_picker;

    public function mount(): void
    {
        $this->user_ids = [auth()->id()];
        $this->unit_id = auth()->user()->person?->u_id;
    }

    public function getEvents()
    {
        return Todo::all()->map(function ($todo) {
            return [
                'id' => $todo->id,
                'title' => $todo->title,
                'start' => $todo->start_at,
                'end' => $todo->end_at,
                'color' => $todo->is_completed ? '#10b981' : '#3b82f6',
                'allDay' => false,
                'extendedProps' => [
                    'is_completed' => $todo->is_completed,
                ],
            ];
        })->toArray();
    }

    // متد جدید برای باز کردن مدال خالی
    public function openModal()
    {
        $this->reset(['title', 'editingId', 'is_completed', 'user_ids', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
        $this->user_ids = [auth()->id()];
        $this->unit_id = auth()->user()->person?->u_id;
        $this->modal = true;
    }

    public function openCreateModal($start, $end)
    {
        $this->reset(['title', 'editingId', 'is_completed', 'user_ids', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
        $this->user_ids = [auth()->id()];
        $this->unit_id = auth()->user()->person?->u_id;
        $this->start_at = $start;
        $this->end_at = $end;
        
        if ($start) {
            $carbon = Carbon::parse($start);
            $this->start_date_picker = Jalalian::fromCarbon($carbon)->format('Y/m/d');
            $this->start_time_picker = $carbon->format('H:i');
        }
        if ($end) {
            $carbon = Carbon::parse($end);
            $this->end_date_picker = Jalalian::fromCarbon($carbon)->format('Y/m/d');
            $this->end_time_picker = $carbon->format('H:i');
        }
        
        $this->modal = true;
    }

    public function editEvent($id)
    {
        $todo = Todo::find($id);
        $this->editingId = $id;
        $this->title = $todo->title;
        $this->is_completed = $todo->is_completed;
        $this->user_ids = $todo->users->pluck('id')->toArray();
        $this->unit_id = $todo->unit_id;
        $this->start_at = $todo->start_at;
        $this->end_at = $todo->end_at;
        
        if ($todo->start_at) {
            $carbon = Carbon::parse($todo->start_at);
            $this->start_date_picker = Jalalian::fromCarbon($carbon)->format('Y/m/d');
            $this->start_time_picker = $carbon->format('H:i');
        }
        if ($todo->end_at) {
            $carbon = Carbon::parse($todo->end_at);
            $this->end_date_picker = Jalalian::fromCarbon($carbon)->format('Y/m/d');
            $this->end_time_picker = $carbon->format('H:i');
        }
        
        $this->modal = true;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|min:3',
            'start_date_picker' => 'required',
        ]);

        $startDateTime = $this->start_date_picker . ' ' . ($this->start_time_picker ?: '00:00');
        $startMildadi = $this->convertToMiladi($startDateTime);
        
        $endMildadi = null;
        if ($this->end_date_picker) {
            $endDateTime = $this->end_date_picker . ' ' . ($this->end_time_picker ?: '00:00');
            $endMildadi = $this->convertToMiladi($endDateTime);
        }

        Todo::updateOrCreate(
            ['id' => $this->editingId],
            [
                'title' => $this->title,
                'start_at' => $startMildadi,
                'end_at' => $endMildadi,
                'is_completed' => $this->is_completed,
                'unit_id' => $this->unit_id,
            ]
        )->users()->sync($this->user_ids);

        $this->success('با موفقیت ذخیره شد');
        $this->modal = false;
        
        // ریست کردن فرم بعد از ذخیره
        $this->reset(['title', 'editingId', 'is_completed', 'user_ids', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
        
        $this->dispatch('calendar-updated', events: $this->getEvents());
    }

    private function convertToMiladi($jalaliDate)
    {
        $parts = explode(' ', $jalaliDate);
        $dateParts = explode('/', $parts[0]);
        $timeParts = isset($parts[1]) ? explode(':', $parts[1]) : [0, 0];
        
        $jalalian = new Jalalian(
            (int)$dateParts[0],
            (int)$dateParts[1],
            (int)$dateParts[2],
            (int)$timeParts[0] ?? 0,
            (int)$timeParts[1] ?? 0,
            0
        );
        
        return $jalalian->toCarbon();
    }

    public function delete(): void
    {
        if ($this->editingId) {
            Todo::find($this->editingId)->delete();
            $this->success('تسک حذف شد');
            $this->modal = false;
            
            // ریست کردن فرم بعد از حذف
            $this->reset(['title', 'editingId', 'is_completed', 'user_ids', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
            
            $this->dispatch('calendar-updated', events: $this->getEvents());
        }
    }

    // متد برای بستن مدال و ریست فرم
    public function closeModal()
    {
        $this->modal = false;
        $this->reset(['title', 'editingId', 'is_completed', 'user_ids', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
    }

    public function toggleComplete(int $id): void
    {
        $todo = Todo::find($id);
        $todo->update(['is_completed' => !$todo->is_completed]);
        $this->dispatch('calendar-updated', events: $this->getEvents());
    }

    public function with(): array
    {
        return [
            'events' => $this->getEvents(),
            'users' => User::whereHas('person', fn($q) => $q->where('u_id', auth()->user()->person?->u_id))
                ->with('person.unit')
                ->get()
                ->map(fn($user) => [
                    'id' => $user->id,
                    'label' => $user->person?->f_name . ' ' . $user->person?->l_name,
                ]),
        ];
    }
}; ?>

<div>
    <x-header title="تقویم کارها (Todo)" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
            <x-button icon="o-plus" label="تسک جدید" class="btn-primary" wire:click="openModal" />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div wire:ignore>
            <div id="calendar" class="min-h-[600px]"></div>
        </div>
    </x-card>

    <x-modal wire:model="modal" title="جزئیات تسک" separator>
        <x-form wire:submit="save">
            <x-input label="عنوان فعالیت" wire:model="title" placeholder="مثلاً: جلسه فنی" />
            
            <div class="space-y-4">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">تاریخ و ساعت شروع</span>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <input 
                            type="text" 
                            wire:model.live="start_date_picker"
                            class="input input-bordered w-full cursor-pointer"
                            placeholder="انتخاب تاریخ"
                            readonly
                            data-jdp
                            data-jdp-time="false"
                            data-jdp-format="YYYY/MM/DD"
                        />
                        <input 
                            type="time" 
                            wire:model.live="start_time_picker"
                            class="input input-bordered w-full"
                        />
                    </div>
                </div>

                <div class="form-control">
                    <label class="label">
                        <span class="label-text">تاریخ و ساعت پایان</span>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <input 
                            type="text" 
                            wire:model.live="end_date_picker"
                            class="input input-bordered w-full cursor-pointer"
                            placeholder="انتخاب تاریخ"
                            readonly
                            data-jdp
                            data-jdp-time="false"
                            data-jdp-format="YYYY/MM/DD"
                        />
                        <input 
                            type="time" 
                            wire:model.live="end_time_picker"
                            class="input input-bordered w-full"
                        />
                    </div>
                </div>
            </div>

            <x-toggle label="انجام شده" wire:model="is_completed" />

            <x-choices-offline
                label="کاربران"
                wire:model="user_ids"
                :options="$users"
                option-value="id"
                option-label="label"
                placeholder="انتخاب کاربران..."
                clearable
                searchable
            />

            <x-slot:actions>
                @if($editingId)
                    <x-button label="حذف" icon="o-trash" class="btn-error" wire:click="delete" wire:confirm="مطمئنی؟" />
                @endif
                <x-button label="لغو" wire:click="closeModal" />
                <x-button label="ذخیره" icon="o-check" class="btn-primary" type="submit" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>


    <script>
        let calendarInstance = null;

        document.addEventListener('DOMContentLoaded', function() {
            jalaliDatepicker.startWatch({
                time: false,
                hasSecond: false,
                format: 'YYYY/MM/DD',
                separatorChars: {
                    date: '/',
                    between: ' ',
                    time: ':'
                }
            });
        });

        document.addEventListener('livewire:init', () => {
            const calendarEl = document.getElementById('calendar');
            if (calendarEl) {
                calendarInstance = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    locale: 'fa',
                    direction: 'rtl',
                    firstDay: 6,
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    buttonText: {
                        today: 'امروز',
                        month: 'ماهانه',
                        week: 'هفتگی',
                        day: 'روزانه',
                        list: 'لیست'
                    },
                    allDayText: 'تمام روز',
                    moreLinkText: 'بیشتر',
                    noEventsText: 'رویدادی برای نمایش وجود ندارد',
                    views: {
                        dayGridMonth: {
                            titleFormat: { year: 'numeric', month: 'long' }
                        },
                        timeGridWeek: {
                            titleFormat: { year: 'numeric', month: 'long', day: 'numeric' }
                        },
                        timeGridDay: {
                            titleFormat: { year: 'numeric', month: 'long', day: 'numeric' }
                        }
                    },
                    selectable: true,
                    editable: true,
                    eventContent: function(arg) {
                        const checkIcon = arg.event.extendedProps.is_completed
                            ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-success cursor-pointer" onclick="event.stopPropagation(); Livewire.find(\'{{ $this->getId() }}\').call(\'toggleComplete\', ' + arg.event.id + ')"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
                            : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-base-content/40 cursor-pointer hover:text-success" onclick="event.stopPropagation(); Livewire.find(\'{{ $this->getId() }}\').call(\'toggleComplete\', ' + arg.event.id + ')"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75" /></svg>';
                        return { html: '<div class="flex items-center gap-1"><span class="fc-event-title">' + arg.timeText + ' ' + arg.event.title + '</span>' + checkIcon + '</div>' };
                    },
                    events: @json($events),
                    select: function(info) {
                        @this.openCreateModal(info.startStr, info.endStr);
                    },
                    eventClick: function(info) {
                        @this.editEvent(info.event.id);
                    },
                    eventDrop: function(info) {
                        @this.openCreateModal(info.event.startStr, info.event.endStr);
                    }
                });

                calendarInstance.render();
            }

            Livewire.on('calendar-updated', (data) => {
                if (calendarInstance && data.events) {
                    calendarInstance.removeAllEvents();
                    data.events.forEach(event => {
                        calendarInstance.addEvent(event);
                    });
                    console.log('تقویم با موفقیت به‌روزرسانی شد');
                }
            });

            Livewire.hook('element.initialized', (el, component) => {
                if (el.id === 'start_date_picker' || el.id === 'end_date_picker') {
                    setTimeout(() => {
                        if (typeof jalaliDatepicker !== 'undefined') {
                            const inputs = document.querySelectorAll('[data-jdp]');
                            inputs.forEach(input => {
                                input.removeAttribute('data-jdp-initialized');
                            });
                            jalaliDatepicker.startWatch();
                        }
                    }, 200);
                }
            });
        });
    </script>

 
</div>
