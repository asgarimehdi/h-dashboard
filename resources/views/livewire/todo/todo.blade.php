<?php

use App\Models\Ticket;
use App\Models\Todo;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use Toast;

    public bool $modal = false;

    public string $title = '';

    public $start_at;

    public $end_at;

    #[Locked]
    public ?int $editingId = null;

    public bool $is_completed = false;

    public ?int $unit_id = null;

    public $start_date_picker;

    public $start_time_picker;

    public $end_date_picker;

    public $end_time_picker;

    private ?string $calendarStart = null;

    private ?string $calendarEnd = null;

    public function mount(): void
    {
        $this->unit_id = session('current_unit_id', auth()->user()->person?->u_id);

        // Issue #1169: the first paint used to run `getEvents()` with a null
        // range — an unbounded query cached under the `:all:` key — and
        // FullCalendar's `datesSet` immediately discarded that payload for a
        // date-filtered one. Seed the window the month grid actually shows so
        // the first render costs the same as the second.
        [$this->calendarStart, $this->calendarEnd] = $this->calendarWindowFor(now());
    }

    /**
     * Issue #1169: the window FullCalendar's `dayGridMonth` really covers.
     * With `firstDay: 6` the grid starts on the Saturday on/before the 1st and
     * ends on the Saturday after the last day — `subWeek()` is not that
     * boundary for every month, which is why a `subWeek()` window never
     * matched `datesSet` and the redundant first refetch survived.
     *
     * Both bounds are ISO 8601 (`toIso8601String()`) so they compare exactly
     * with the `info.startStr` / `info.endStr` FullCalendar sends.
     *
     * `\Carbon\CarbonInterface` is spelled out on purpose: an import above the
     * anonymous class would shift it off line 14, and the PHPStan baseline is
     * keyed to that line.
     *
     * @return array{0: string, 1: string}
     */
    public function calendarWindowFor(\Carbon\CarbonInterface $date): array
    {
        $start = $date->copy()->startOfMonth()->startOfDay();
        $start->subDays(((int) $start->format('w') + 1) % 7);

        $end = $date->copy()->endOfMonth()->startOfDay();
        $daysToSaturday = (6 - (int) $end->format('w')) % 7;
        $end->addDays($daysToSaturday === 0 ? 7 : $daysToSaturday);

        return [$start->toIso8601String(), $end->toIso8601String()];
    }

    public function fetchEvents(string $start, string $end): void
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $this->calendarStart = $start;
        $this->calendarEnd = $end;
        $this->dispatch('calendar-updated', events: $this->getEvents());
    }

    public function getEvents(): array
    {
        $user = auth()->user();
        $accessService = app(AccessService::class);
        $accessibleIds = $accessService->accessibleUnitIds($user);

        // Build cache key from accessible IDs + date range + user context (Issue #389).
        // Issue #1290: the service owns the version and hashes the extra axis.
        return app(CacheInvalidationServiceInterface::class)->remember('calendar', \App\Support\ScopeCacheKey::hash($accessibleIds), function () use ($accessibleIds) {
            // Base query with date range filtering for performance.
            // Null-unit contract (#838): a null-unit todo belongs to its creator —
            // listed only to the creator, never to every calendar holder.
            $userId = Auth::id();
            $todoQuery = Todo::query()
                ->where(function ($q) use ($accessibleIds, $userId) {
                    $q->whereIn('unit_id', $accessibleIds)
                        ->orWhere(fn ($qq) => $qq->whereNull('unit_id')->where('user_id', $userId));
                });

            // تیکت‌ها.
            // #847: `task_title` leaked a todo the viewer cannot reach. This
            // page is gated by `calendar` alone — not a ticket permission at
            // all — so it was the cheapest read in the whole chain. The scope
            // goes on the eager-load itself, so an out-of-scope task arrives as
            // `null` and the title is never emitted. A null-unit todo belongs
            // to its creator (#838 contract).
            $taskScope = fn ($q) => $q->whereIn('unit_id', $accessibleIds)
                ->orWhere(fn ($q) => $q->whereNull('unit_id')->where('user_id', \Illuminate\Support\Facades\Auth::id()));

            $ticketQuery = Ticket::accessible()
                ->whereHas('task')
                ->with(['task' => $taskScope])
                ->whereIn('status', ['created', 'forwarded', 'accepted']);

            // Apply date range filter when available
            if ($this->calendarStart) {
                $todoQuery->where('start_at', '>=', $this->calendarStart);
                $ticketQuery->where('created_at', '>=', $this->calendarStart);
            }
            if ($this->calendarEnd) {
                $todoQuery->where('start_at', '<=', $this->calendarEnd);
                $ticketQuery->where('created_at', '<=', $this->calendarEnd);
            }

            // وظایف
            $todoEvents = $todoQuery
                ->get()
                ->map(function ($todo) {
                    return [
                        'id' => 'todo-'.$todo->id,
                        'title' => $todo->title,
                        'start' => $todo->start_at,
                        'end' => $todo->end_at,
                        'color' => $todo->is_completed ? '#10b981' : '#3b82f6',
                        'allDay' => false,
                        'extendedProps' => [
                            'type' => 'todo',
                            'is_completed' => $todo->is_completed,
                        ],
                    ];
                })->toArray();

            // تیکت‌ها
            $ticketEvents = $ticketQuery
                ->get()
                ->map(function ($ticket) {
                    $priorityColors = ['urgent' => '#ef4444', 'normal' => '#f59e0b', 'low' => '#6b7280'];
                    $priorityLabels = ['urgent' => 'فوری', 'normal' => 'عادی', 'low' => 'کم‌اهمیت'];

                    return [
                        'id' => 'ticket-'.$ticket->id,
                        'title' => '🎫 '.$ticket->subject,
                        'start' => $ticket->created_at,
                        'color' => $priorityColors[$ticket->priority] ?? '#f59e0b',
                        'allDay' => false,
                        'extendedProps' => [
                            'type' => 'ticket',
                            'ticket_code' => $ticket->ticket_code,
                            'status' => $ticket->status_name,
                            'priority' => $priorityLabels[$ticket->priority] ?? 'عادی',
                            'task_id' => $ticket->task?->id,
                            'task_title' => $ticket->task?->title,
                        ],
                    ];
                })->toArray();

            return array_merge($todoEvents, $ticketEvents);
        }, 2, [
            'user' => $user->id,
            'start' => $this->calendarStart ?? 'all',
            'end' => $this->calendarEnd ?? 'all',
        ]);
    }

    public function openModal()
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $this->reset(['title', 'editingId', 'is_completed', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
        $this->unit_id = session('current_unit_id', auth()->user()->person?->u_id);
        $this->modal = true;
    }

    public function openCreateModal($start, $end)
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $this->reset(['title', 'editingId', 'is_completed', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
        $this->unit_id = session('current_unit_id', auth()->user()->person?->u_id);
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

    public function updateTodoDates(string $todoId, string $startDate, ?string $endDate = null): void
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $todo = Todo::find($todoId);

        if (! $todo || ! $this->isTodoAccessible($todo)) {
            return;
        }

        $todo->update([
            'start_at' => $startDate,
            'end_at' => $endDate ?? $startDate,
        ]);

        app(CacheInvalidationServiceInterface::class)->increment('calendar');

        $this->dispatch('swal', ['title' => 'تاریخ وظیفه به‌روزرسانی شد', 'icon' => 'success']);
        $this->mount();
    }

    public function editEvent($id): void
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $todo = Todo::find($id);

        if (! $this->isTodoAccessible($todo)) {
            $this->error('شما دسترسی به این تسک را ندارید');

            return;
        }

        $this->editingId = $id;
        $this->title = $todo->title;
        $this->is_completed = $todo->is_completed;
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
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $this->validate([
            'title' => 'required|min:3',
            'start_date_picker' => 'required',
        ]);

        // Validate unit_id is accessible
        $accessibleIds = app(AccessService::class)->accessibleUnitIds(auth()->user());
        if ($this->unit_id !== null && ! in_array($this->unit_id, $accessibleIds, true)) {
            $this->error('شما مجاز به ایجاد/ویرایش تسک در این واحد نیستید');

            return;
        }

        // #838: scope-check the TARGET row, not just the submitted unit_id.
        // Without this, editingId can point at a foreign-unit todo and
        // updateOrCreate rewrites + relocates it into the actor's unit.
        if ($this->editingId) {
            $stored = Todo::find($this->editingId);

            if (! $this->isTodoAccessible($stored)) {
                $this->error('شما دسترسی به این تسک را ندارید');

                return;
            }

            // Relocation-safe: an edit must not park a unit-scoped row into
            // the ownerless null-unit bucket.
            if ($this->unit_id === null && $stored->unit_id !== null) {
                $this->error('شما مجاز به ایجاد/ویرایش تسک در این واحد نیستید');

                return;
            }
        }

        $startDateTime = $this->start_date_picker.' '.($this->start_time_picker ?: '00:00');
        $startMildadi = $this->convertToMiladi($startDateTime);

        $endMildadi = null;
        if ($this->end_date_picker) {
            $endDateTime = $this->end_date_picker.' '.($this->end_time_picker ?: '00:00');
            $endMildadi = $this->convertToMiladi($endDateTime);
        }

        $todo = Todo::updateOrCreate(
            ['id' => $this->editingId],
            [
                'title' => $this->title,
                'start_at' => $startMildadi,
                'end_at' => $endMildadi,
                'is_completed' => $this->is_completed,
                'unit_id' => $this->unit_id,
            ]
        );

        if (! $this->editingId) {
            $todo->update(['user_id' => Auth::id()]);
        }

        $this->success('با موفقیت ذخیره شد');
        $this->modal = false;

        $this->reset(['title', 'editingId', 'is_completed', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);

        $this->invalidateEventCache();
        $this->dispatch('calendar-updated', events: $this->getEvents());
    }

    private function convertToMiladi($jalaliDate)
    {
        $parts = explode(' ', $jalaliDate);
        $dateParts = explode('/', $parts[0]);
        $timeParts = isset($parts[1]) ? explode(':', $parts[1]) : [0, 0];

        $jalalian = new Jalalian(
            (int) $dateParts[0],
            (int) $dateParts[1],
            (int) $dateParts[2],
            (int) $timeParts[0],
            (int) $timeParts[1],
            0
        );

        return $jalalian->toCarbon();
    }

    /**
     * Invalidate calendar event cache for current user
     */
    private function invalidateEventCache(): void
    {
        // Version-based invalidation covers all users and date ranges (Issue #389)
        app(CacheInvalidationServiceInterface::class)->increment('calendar');
    }

    public function delete(): void
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        if ($this->editingId) {
            $todo = Todo::find($this->editingId);

            if (! $this->isTodoAccessible($todo)) {
                $this->error('شما دسترسی به این تسک را ندارید');

                return;
            }

            try {
                $todo->delete();
                $this->success('تسک حذف شد');
                $this->modal = false;

                $this->reset(['title', 'editingId', 'is_completed', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);

                $this->invalidateEventCache();
                $this->dispatch('calendar-updated', events: $this->getEvents());
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                // Issue #892/#975/#978: defence in depth. The `authorize()` above sits
                // OUTSIDE this try, so the 403 never actually reaches here today —
                // this clause exists so that a later edit which moves the check
                // inside the try cannot turn a revoked holder's delete back into a
                // friendly toast, and so the #892/#975 static scan keeps a handle on
                // the ordering.
                throw $e;
            } catch (\Exception $e) {
                $this->error('امکان حذف وجود ندارد', position: 'toast-bottom');
            }
        }
    }

    public function closeModal()
    {
        $this->modal = false;
        $this->reset(['title', 'editingId', 'is_completed', 'start_date_picker', 'start_time_picker', 'end_date_picker', 'end_time_picker']);
    }

    public function toggleComplete(int $id): void
    {
        // Issue #892/#975/#978: the route gate (`role_or_permission:calendar`)
        // does not run on `/livewire/update`, so this mutator re-checks it here.
        // It must come FIRST — nothing below is scoped by anything a later
        // check could undo.
        $this->authorize('calendar');

        $todo = Todo::find($id);

        if (! $this->isTodoAccessible($todo)) {
            $this->error('شما دسترسی به این تسک را ندارید');

            return;
        }

        $todo->update(['is_completed' => ! $todo->is_completed]);
        $this->invalidateEventCache();
        $this->dispatch('calendar-updated', events: $this->getEvents());
    }

    public function with(): array
    {
        return [
            'events' => $this->getEvents(),
            // Issue #1169: the window the client compares its first `datesSet`
            // callback against. It resolves no todo/ticket data, so it needs no
            // `calendar` gate of its own.
            'calendarWindow' => [
                'start' => $this->calendarStart,
                'end' => $this->calendarEnd,
            ],
        ];
    }

    private function isTodoAccessible(?Todo $todo): bool
    {
        if (! $todo) {
            return false;
        }
        // Null-unit contract (#838): a null-unit todo belongs to its creator.
        if ($todo->unit_id === null) {
            return $todo->user_id === Auth::id();
        }
        $accessibleIds = app(AccessService::class)->accessibleUnitIds(auth()->user());

        return in_array($todo->unit_id, $accessibleIds);
    }
}; ?>

<div>
    <x-header title="تقویم سازمانی" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
            <x-button icon="o-plus" label="تسک جدید" class="btn-primary" wire:click="openModal" />
        </x-slot:actions>
    </x-header>

    {{-- لگند رنگ‌ها --}}
    <div class="flex flex-wrap gap-3 mb-4 px-2">
        <div class="flex items-center gap-1.5">
            <div class="w-3 h-3 rounded-full bg-[#3b82f6]"></div>
            <span class="text-xs">وظیفه در انتظار</span>
        </div>
        <div class="flex items-center gap-1.5">
            <div class="w-3 h-3 rounded-full bg-[#10b981]"></div>
            <span class="text-xs">وظیفه انجام شده</span>
        </div>
        <div class="flex items-center gap-1.5">
            <div class="w-3 h-3 rounded-full bg-[#ef4444]"></div>
            <span class="text-xs">تیکت فوری</span>
        </div>
        <div class="flex items-center gap-1.5">
            <div class="w-3 h-3 rounded-full bg-[#f59e0b]"></div>
            <span class="text-xs">تیکت عادی</span>
        </div>
        <div class="flex items-center gap-1.5">
            <div class="w-3 h-3 rounded-full bg-[#6b7280]"></div>
            <span class="text-xs">تیکت کم‌اهمیت</span>
        </div>
    </div>

    <x-card shadow>
        <div wire:ignore>
            <div id="calendar" class="min-h-[600px]"></div>
        </div>
    </x-card>

    {{-- Inert data for the FullCalendar setup in todo-calendar.js.
         Must sit INSIDE the root element: Livewire drops trailing
         content after the root close tag (chunk-5 lesson). --}}
    <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="todo-calendar-data">@json(['events' => $events, 'window' => $calendarWindow])</script>

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

            <x-slot:actions>
                @if($editingId)
                    <x-ui.icon-button name="حذف کار" icon="o-trash" class="btn-error" wire:click="delete" wire:confirm="مطمئنی؟" />
                @endif
                <x-button label="لغو" wire:click="closeModal" />
                <x-button label="ذخیره" icon="o-check" class="btn-primary" type="submit" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>

@assets
    {{-- Issue #984 / #1182: FullCalendar is used by this calendar page alone
         (~275 KB) and jalalidatepicker by its date fields; both ship here instead
         of in the global layout. `@assets` (not `@push('scripts')`) — the tags
         belong in <head>, ahead of the page scripts, and Livewire evaluates new
         head assets before completing a `wire:navigate` swap. --}}
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
    <script src="{{ asset('js/other/full-calendar.min.js') }}"></script>
@endassets



</div>
