<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\{User, Ticket, Todo, ActivityLog};
use App\Services\AccessService;

return new class extends Component
{
    use WithPagination;

    public $user;
    public int $totalTickets = 0;
    public int $completedTickets = 0;
    public int $pendingTickets = 0;
    public int $totalTodos = 0;
    public int $completedTodos = 0;

    public function mount(): void
    {
        $this->user = auth()->user()->load(['person', 'units']);
        $userId = auth()->id();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->totalTickets = Ticket::where('user_id', $userId)->accessible()->count();
        $this->completedTickets = Ticket::where('user_id', $userId)->accessible()->where('status', 'completed')->count();
        $this->pendingTickets = $this->totalTickets - $this->completedTickets;

        $this->totalTodos = Todo::accessible()->count();
        $this->completedTodos = Todo::accessible()->where('is_completed', true)->count();
    }

    public function getUserTicketsProperty()
    {
        return Ticket::where('user_id', auth()->id())
            ->accessible()
            ->with('unit')
            ->latest()
            ->paginate(10, pageName: 'tickets_page');
    }

    public function getUserTodosProperty()
    {
        return Todo::accessible()
            ->latest()
            ->paginate(10, pageName: 'todos_page');
    }

    public function getUserActivitiesProperty()
    {
        return ActivityLog::where('user_id', auth()->id())
            ->latest()
            ->paginate(15, pageName: 'activities_page');
    }

}; ?>

<div dir="rtl">
    {{-- Header --}}
    <x-header title="پروفایل من" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    {{-- User Info Card --}}
    <x-card shadow class="mb-6">
        <div class="flex items-center gap-6">
            {{-- Avatar --}}
            <div class="avatar placeholder flex-shrink-0">
                <div class="bg-primary text-primary-content rounded-full w-20 h-20">
                    <span class="text-3xl font-bold">{{ mb_substr($user->name, 0, 1) }}</span>
                </div>
            </div>

            {{-- User Details --}}
            <div class="flex-1 min-w-0">
                <h2 class="text-xl font-bold">{{ $user->name }}</h2>
                <div class="flex flex-wrap gap-3 mt-2 text-sm text-base-content/60">
                    <span class="flex items-center gap-1">
                        <x-icon name="o-identification" class="w-4 h-4" />
                        کد ملی: {{ $user->n_code ?? '-' }}
                    </span>
                    <span class="flex items-center gap-1">
                        <x-icon name="o-building-office" class="w-4 h-4" />
                        {{ $user->person?->unit?->name ?? '-' }}
                    </span>
                    @if($user->units && $user->units->count())
                        <span class="flex items-center gap-1">
                            <x-icon name="o-user-group" class="w-4 h-4" />
                            {{ $user->units->pluck('name')->join('، ') }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Quick Actions --}}
            <div class="flex gap-2 flex-shrink-0">
                <x-button icon="o-key" label="تغییر رمز عبور" class="btn-outline btn-sm" wire:navigate />
            </div>
        </div>
    </x-card>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-stat
            title="کل تیکت‌ها"
            value="{{ number_format($totalTickets) }}"
            icon="o-ticket"
            color="text-info"
            description="تعداد کل تیکت‌های ثبت‌شده"
        />
        <x-stat
            title="تکمیل شده"
            value="{{ number_format($completedTickets) }}"
            icon="o-check-circle"
            color="text-success"
            description="تیکت‌های پایان‌یافته"
        />
        <x-stat
            title="در انتظار"
            value="{{ number_format($pendingTickets) }}"
            icon="o-arrow-path"
            color="text-warning"
            description="تیکت‌های فعال"
        />
        <x-stat
            title="وظایف"
            value="{{ $completedTodos }}/{{ $totalTodos }}"
            icon="o-calendar-days"
            color="text-primary"
            description="انجام شده از کل"
        />
    </div>

    {{-- Tabs Section --}}
    <div x-data="{ activeTab: 'tickets' }">
        {{-- Tab Navigation --}}
        <div class="tabs tabs-boxed mb-4 bg-base-200 p-1 rounded-lg">
            <a class="tab flex-1 gap-2 transition-all"
               :class="{ 'tab-active bg-base-100 shadow-sm font-bold': activeTab === 'tickets' }"
               @click="activeTab = 'tickets'">
                <x-icon name="o-ticket" class="w-4 h-4" />
                تیکت‌ها
                <x-badge :value="$totalTickets" class="badge-sm badge-info" />
            </a>
            <a class="tab flex-1 gap-2 transition-all"
               :class="{ 'tab-active bg-base-100 shadow-sm font-bold': activeTab === 'todos' }"
               @click="activeTab = 'todos'">
                <x-icon name="o-calendar-days" class="w-4 h-4" />
                وظایف
                <x-badge :value="$totalTodos" class="badge-sm badge-primary" />
            </a>
            <a class="tab flex-1 gap-2 transition-all"
               :class="{ 'tab-active bg-base-100 shadow-sm font-bold': activeTab === 'activities' }"
               @click="activeTab = 'activities'">
                <x-icon name="o-clock" class="w-4 h-4" />
                فعالیت‌ها
            </a>
        </div>

        {{-- Tickets Tab --}}
        <div x-show="activeTab === 'tickets'" x-transition>
            <x-card shadow>
                <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                    <x-icon name="o-ticket" class="w-5 h-5 text-info" />
                    تیکت‌های من
                </h3>

                @forelse($this->userTickets as $ticket)
                    <div class="flex items-center gap-3 p-3 rounded-lg hover:bg-base-200 transition border-b border-base-200 last:border-0">
                        {{-- Priority Indicator --}}
                        @php
                            $priorityClass = match($ticket->priority) {
                                'urgent' => 'bg-error',
                                'normal' => 'bg-info',
                                'low' => 'bg-base-content/20',
                                default => 'bg-base-content/20',
                            };
                        @endphp
                        <div class="w-1.5 h-10 rounded-full {{ $priorityClass }} flex-shrink-0"></div>

                        {{-- Ticket Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="font-bold text-sm truncate">{{ $ticket->subject }}</p>
                                @if($ticket->ticket_code)
                                    <span class="text-[10px] text-base-content/40 font-mono">#{{ $ticket->ticket_code }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-base-content/50 mt-0.5">
                                {{ $ticket->unit?->name ?? 'بدون واحد' }}
                            </p>
                        </div>

                        {{-- Status Badge --}}
                        @php
                            $statusClass = match($ticket->status) {
                                'created' => 'badge-info',
                                'accepted' => 'badge-warning',
                                'completed' => 'badge-success',
                                'forwarded' => 'badge-secondary',
                                'rejected' => 'badge-error',
                                default => 'badge-ghost',
                            };
                        @endphp
                        <x-badge :value="$ticket->status_name" class="{{ $statusClass }}" rounded />

                        {{-- Time --}}
                        <span class="text-[10px] text-base-content/40 whitespace-nowrap">
                            {{ $ticket->created_at->diffForHumans() }}
                        </span>
                    </div>
                @empty
                    <div class="text-center py-12 text-base-content/30">
                        <x-icon name="o-ticket" class="w-12 h-12 mx-auto mb-3" />
                        <p class="text-sm">هنوز تیکتی ثبت نشده</p>
                    </div>
                @endforelse

                @if($this->userTickets->hasPages())
                    <div class="mt-4 pt-4 border-t border-base-200">
                        {{ $this->userTickets->links() }}
                    </div>
                @endif
            </x-card>
        </div>

        {{-- Todos Tab --}}
        <div x-show="activeTab === 'todos'" x-transition>
            <x-card shadow>
                <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                    <x-icon name="o-calendar-days" class="w-5 h-5 text-primary" />
                    وظایف من
                </h3>

                @forelse($this->userTodos as $todo)
                    <div class="flex items-center gap-3 p-3 rounded-lg hover:bg-base-200 transition border-b border-base-200 last:border-0">
                        {{-- Completion Status --}}
                        @if($todo->is_completed)
                            <x-icon name="o-check-circle" class="w-5 h-5 text-success flex-shrink-0" />
                        @else
                            <x-icon name="o-clock" class="w-5 h-5 text-warning flex-shrink-0" />
                        @endif

                        {{-- Todo Info --}}
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-sm {{ $todo->is_completed ? 'line-through text-base-content/50' : '' }}">
                                {{ $todo->title }}
                            </p>
                            <div class="flex items-center gap-3 text-[10px] text-base-content/40 mt-0.5">
                                @if($todo->start_at)
                                    <span>شروع: {{ \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($todo->start_at))->format('Y/m/d') }}</span>
                                @endif
                                @if($todo->end_at)
                                    <span>پایان: {{ \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($todo->end_at))->format('Y/m/d') }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Status Badge --}}
                        @if($todo->is_completed)
                            <x-badge value="انجام شده" class="badge-success" rounded />
                        @else
                            <x-badge value="در انتظار" class="badge-warning" rounded />
                        @endif
                    </div>
                @empty
                    <div class="text-center py-12 text-base-content/30">
                        <x-icon name="o-calendar-days" class="w-12 h-12 mx-auto mb-3" />
                        <p class="text-sm">هنوز وظیفه‌ای ثبت نشده</p>
                    </div>
                @endforelse

                @if($this->userTodos->hasPages())
                    <div class="mt-4 pt-4 border-t border-base-200">
                        {{ $this->userTodos->links() }}
                    </div>
                @endif
            </x-card>
        </div>

        {{-- Activities Tab --}}
        <div x-show="activeTab === 'activities'" x-transition>
            <x-card shadow>
                <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                    <x-icon name="o-clock" class="w-5 h-5 text-secondary" />
                    آخرین فعالیت‌ها
                </h3>

                @forelse($this->userActivities as $activity)
                    @php
                        $icon = match($activity->type) {
                            'created' => ['o-plus-circle', 'text-success'],
                            'updated' => ['o-pencil-square', 'text-info'],
                            'deleted' => ['o-trash', 'text-error'],
                            'login' => ['o-arrow-right-on-rectangle', 'text-primary'],
                            'logout' => ['o-arrow-left-on-rectangle', 'text-warning'],
                            default => ['o-document-text', 'text-base-content'],
                        };
                        $typeLabel = match($activity->type) {
                            'created' => 'ایجاد',
                            'updated' => 'ویرایش',
                            'deleted' => 'حذف',
                            'login' => 'ورود',
                            'logout' => 'خروج',
                            default => $activity->type,
                        };
                    @endphp
                    <div class="flex items-center gap-3 p-3 rounded-lg hover:bg-base-200 transition border-b border-base-200 last:border-0">
                        <x-icon name="{{ $icon[0] }}" class="w-5 h-5 {{ $icon[1] }} flex-shrink-0" />

                        <div class="flex-1 min-w-0">
                            <p class="text-sm truncate">{{ $activity->description }}</p>
                            <span class="text-[10px] text-base-content/40">{{ $typeLabel }}</span>
                        </div>

                        <span class="text-[10px] text-base-content/40 whitespace-nowrap">
                            {{ $activity->created_at->diffForHumans() }}
                        </span>
                    </div>
                @empty
                    <div class="text-center py-12 text-base-content/30">
                        <x-icon name="o-clock" class="w-12 h-12 mx-auto mb-3" />
                        <p class="text-sm">هنوز فعالیتی ثبت نشده</p>
                    </div>
                @endforelse

                @if($this->userActivities->hasPages())
                    <div class="mt-4 pt-4 border-t border-base-200">
                        {{ $this->userActivities->links() }}
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</div>
