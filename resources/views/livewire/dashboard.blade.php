<?php

use App\Models\{User, Person, Unit, Ticket, Todo};
use App\Services\AccessService;
use Livewire\Component;
use Spatie\Permission\Models\Role;

return new class extends Component {
    public int $totalUsers = 0;
    public int $totalPersons = 0;
    public int $totalUnits = 0;
    public int $totalTickets = 0;
    public int $openTickets = 0;
    public int $completedTickets = 0;
    public int $totalTodos = 0;
    public int $pendingTodos = 0;
    public int $completedTodos = 0;
    public int $linkedTodos = 0;
    public int $totalRoles = 0;

    public function mount(): void
    {
        $user = auth()->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->totalUsers = User::count();
        $this->totalPersons = Person::whereIn('u_id', $accessibleIds)->count();
        $this->totalUnits = Unit::whereIn('id', $accessibleIds)->count();
        $this->totalRoles = Role::count();

        // آمار تیکت‌ها
        $this->totalTickets = Ticket::whereIn('unit_id', $accessibleIds)->count();
        $this->openTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->whereIn('status', ['created', 'forwarded'])
            ->count();
        $this->completedTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->where('status', 'completed')
            ->count();

        // آمار وظایف
        $this->totalTodos = Todo::whereIn('unit_id', $accessibleIds)->count();
        $this->pendingTodos = Todo::whereIn('unit_id', $accessibleIds)
            ->where('is_completed', false)
            ->count();
        $this->completedTodos = Todo::whereIn('unit_id', $accessibleIds)
            ->where('is_completed', true)
            ->count();
        $this->linkedTodos = Todo::whereIn('unit_id', $accessibleIds)
            ->has('tickets')
            ->count();
    }
}; ?>

<div>
    <x-header title="داشبورد مدیریت اطلاعات سلامت" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat
            title="کاربران"
            value="{{ number_format($totalUsers) }}"
            icon="o-users"
            color="text-primary"
            description="تعداد کل کاربران سیستم"
        />
        <x-stat
            title="پرسنل"
            value="{{ number_format($totalPersons) }}"
            icon="o-user-group"
            color="text-secondary"
            description="تعداد کل پرسنل ثبت‌شده"
        />
        <x-stat
            title="واحدها"
            value="{{ number_format($totalUnits) }}"
            icon="o-building-office-2"
            color="text-accent"
            description="تعداد کل واحدهای سازمانی"
        />
        <x-stat
            title="نقش‌ها"
            value="{{ number_format($totalRoles) }}"
            icon="o-key"
            color="text-neutral"
            description="تعداد نقش‌های تعریف‌شده"
        />
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <x-stat
            title="کل تیکت‌ها"
            value="{{ number_format($totalTickets) }}"
            icon="o-ticket"
            color="text-info"
            description="تعداد کل تیکت‌های ثبت‌شده"
        />
        <x-stat
            title="تیکت‌های باز"
            value="{{ number_format($openTickets) }}"
            icon="o-arrow-path"
            color="text-warning"
            description="تیکت‌های در انتظار بررسی"
        />
        <x-stat
            title="تیکت‌های تکمیل شده"
            value="{{ number_format($completedTickets) }}"
            icon="o-check-circle"
            color="text-success"
            description="تعداد تیکت‌های بسته شده"
        />
    </div>

    {{-- نمودار پیشرفت وظایف --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        {{-- کارت آمار وظایف --}}
        <x-card shadow>
            <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                <x-icon name="o-calendar-days" class="w-5 h-5 text-primary" />
                وضعیت وظایف
            </h3>
            <div class="space-y-4">
                @php
                    $todoPercent = $totalTodos > 0 ? round(($completedTodos / $totalTodos) * 100) : 0;
                @endphp
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span>انجام شده</span>
                        <span class="font-bold">{{ $completedTodos }} / {{ $totalTodos }} ({{ $todoPercent }}%)</span>
                    </div>
                    <div class="w-full bg-base-200 rounded-full h-3">
                        <div class="bg-success h-3 rounded-full transition-all duration-500" style="width: {{ $todoPercent }}%"></div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4 pt-4 border-t border-base-200">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-info">{{ $totalTodos }}</div>
                        <div class="text-xs text-base-content/50">کل وظایف</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-warning">{{ $pendingTodos }}</div>
                        <div class="text-xs text-base-content/50">در انتظار</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-success">{{ $completedTodos }}</div>
                        <div class="text-xs text-base-content/50">انجام شده</div>
                    </div>
                </div>
            </div>
        </x-card>

        {{-- کارت ارتباط تیکت و وظایف --}}
        <x-card shadow>
            <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                <x-icon name="o-link" class="w-5 h-5 text-secondary" />
                ارتباط تیکت و وظایف
            </h3>
            <div class="space-y-4">
                @php
                    $linkedPercent = $totalTodos > 0 ? round(($linkedTodos / $totalTodos) * 100) : 0;
                    $unlinkedTodos = $totalTodos - $linkedTodos;
                @endphp
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span>وظایف با تیکت مرتبط</span>
                        <span class="font-bold">{{ $linkedTodos }} / {{ $totalTodos }} ({{ $linkedPercent }}%)</span>
                    </div>
                    <div class="w-full bg-base-200 rounded-full h-3">
                        <div class="bg-secondary h-3 rounded-full transition-all duration-500" style="width: {{ $linkedPercent }}%"></div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 pt-4 border-t border-base-200">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-secondary">{{ $linkedTodos }}</div>
                        <div class="text-xs text-base-content/50">با تیکت</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-base-content/30">{{ $unlinkedTodos }}</div>
                        <div class="text-xs text-base-content/50">بدون تیکت</div>
                    </div>
                </div>

                <div class="pt-4 border-t border-base-200">
                    <div class="flex items-center gap-2 text-xs text-base-content/50">
                        <x-icon name="o-information-circle" class="w-4 h-4" />
                        <span>وظایفی که حداقل یک تیکت به آنها مرتبط شده است</span>
                    </div>
                </div>
            </div>
        </x-card>
    </div>
</div>