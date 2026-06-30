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
    public int $pendingTodos = 0;
    public int $totalRoles = 0;

    public function mount(): void
    {
        $user = auth()->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->totalUsers = User::count();
        $this->totalPersons = Person::whereIn('u_id', $accessibleIds)->count();
        $this->totalUnits = Unit::whereIn('id', $accessibleIds)->count();
        $this->totalTickets = Ticket::whereIn('unit_id', $accessibleIds)->count();
        $this->openTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->whereIn('status', ['created', 'forwarded'])
            ->count();
        $this->pendingTodos = Todo::whereIn('unit_id', $accessibleIds)
            ->where('is_completed', false)
            ->count();
        $this->totalRoles = Role::count();
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

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
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
            title="وظایف انجام‌نشده"
            value="{{ number_format($pendingTodos) }}"
            icon="o-calendar-days"
            color="text-success"
            description="تعداد وظایف باقی‌مانده"
        />
    </div>
</div>