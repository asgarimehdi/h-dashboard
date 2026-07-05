<?php

use App\Models\{User, Person, Unit, Ticket, Todo, ActivityLog};
use App\Services\AccessService;
use Illuminate\Support\Facades\DB;
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
    // آمار امروز
    public int $todayTickets = 0;
    public int $todayTodos = 0;
    public int $todayActivities = 0;
    // آمار تفصیلی تیکت‌ها
    public int $urgentTickets = 0;
    public int $normalTickets = 0;
    public int $lowTickets = 0;
    public int $overdueTickets = 0;
    public float $avgResolutionDays = 0;

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

        // آمار امروز
        $today = now()->startOfDay();
        $this->todayTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->where('created_at', '>=', $today)
            ->count();
        $this->todayTodos = Todo::whereIn('unit_id', $accessibleIds)
            ->where('created_at', '>=', $today)
            ->count();
        $this->todayActivities = ActivityLog::count();

        // آمار تفصیلی تیکت‌ها
        $this->urgentTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->where('priority', 'urgent')
            ->whereIn('status', ['created', 'forwarded'])
            ->count();
        $this->normalTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->where('priority', 'normal')
            ->whereIn('status', ['created', 'forwarded'])
            ->count();
        $this->lowTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->where('priority', 'low')
            ->whereIn('status', ['created', 'forwarded'])
            ->count();
        $this->overdueTickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->whereIn('status', ['created', 'forwarded'])
            ->where('deadline', '<', now())
            ->count();
        $diffExpr = match (DB::getDriverName()) {
            'pgsql' => 'EXTRACT(EPOCH FROM (completed_at - created_at)) / 86400',
            default => 'DATEDIFF(completed_at, created_at)',
        };
        $this->avgResolutionDays = Ticket::whereIn('unit_id', $accessibleIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->avg(DB::raw($diffExpr)) ?? 0;
    }

    // داده‌های نمودار تیکت‌ها
    public function getTicketChartDataProperty(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $tickets = Ticket::whereIn('unit_id', $accessibleIds)
            ->selectRaw("date(created_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->limit(30)
            ->get();

        return [
            'categories' => $tickets->pluck('day')->map(fn($d) => \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($d))->format('m/d'))->toArray(),
            'series' => $tickets->pluck('count')->toArray(),
        ];
    }

    // داده‌های نمودار وضعیت تیکت‌ها
    public function getTicketStatusDataProperty(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Ticket::whereIn('unit_id', $accessibleIds)
            ->selectRaw("status, count(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    // آخرین فعالیت‌ها
    public function getRecentActivitiesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::with('user')->latest()->take(10)->get();
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

    {{-- نمودارهای تعاملی --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        {{-- نمودار روند تیکت‌ها --}}
        <x-card shadow>
            <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                <x-icon name="o-chart-bar" class="w-5 h-5 text-info" />
                روند ایجاد تیکت‌ها (۳۰ روز اخیر)
            </h3>
            <div id="ticketTrendChart" wire:ignore style="height: 250px;"></div>
        </x-card>

        {{-- نمودار وضعیت تیکت‌ها --}}
        <x-card shadow>
            <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                <x-icon name="o-chart-pie" class="w-5 h-5 text-warning" />
                وضعیت تیکت‌ها
            </h3>
            <div id="ticketStatusChart" wire:ignore style="height: 250px;"></div>
        </x-card>
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

    {{-- خلاصه امروز --}}
    <x-card shadow>
        <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
            <x-icon name="o-sun" class="w-5 h-5 text-warning" />
            خلاصه امروز
        </h3>
        <div class="grid grid-cols-3 gap-4">
            <div class="text-center p-3 bg-info/10 rounded-xl">
                <div class="text-2xl font-bold text-info">{{ $todayTickets }}</div>
                <div class="text-xs text-base-content/50">تیکت جدید</div>
            </div>
            <div class="text-center p-3 bg-success/10 rounded-xl">
                <div class="text-2xl font-bold text-success">{{ $todayTodos }}</div>
                <div class="text-xs text-base-content/50">وظیفه جدید</div>
            </div>
            <div class="text-center p-3 bg-warning/10 rounded-xl">
                <div class="text-2xl font-bold text-warning">{{ $todayActivities }}</div>
                <div class="text-xs text-base-content/50">فعالیت کل</div>
            </div>
        </div>
    </x-card>

    {{-- آمار تفصیلی تیکت‌ها --}}
    <x-card shadow>
        <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
            <x-icon name="o-chart-bar" class="w-5 h-5 text-primary" />
            آمار تفصیلی تیکت‌ها
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="stat bg-error/10 rounded-xl p-4">
                <div class="stat-title text-xs text-error">فوری</div>
                <div class="stat-value text-lg font-bold text-error">{{ $urgentTickets }}</div>
            </div>
            <div class="stat bg-warning/10 rounded-xl p-4">
                <div class="stat-title text-xs text-warning">عادی</div>
                <div class="stat-value text-lg font-bold text-warning">{{ $normalTickets }}</div>
            </div>
            <div class="stat bg-success/10 rounded-xl p-4">
                <div class="stat-title text-xs text-success">کم‌اهمیت</div>
                <div class="stat-value text-lg font-bold text-success">{{ $lowTickets }}</div>
            </div>
            <div class="stat bg-error/10 rounded-xl p-4">
                <div class="stat-title text-xs text-error">سررسید گذشته</div>
                <div class="stat-value text-lg font-bold text-error">{{ $overdueTickets }}</div>
            </div>
            <div class="stat bg-info/10 rounded-xl p-4">
                <div class="stat-title text-xs text-info">میانگین حل (روز)</div>
                <div class="stat-value text-lg font-bold text-info">{{ number_format($avgResolutionDays, 1) }}</div>
            </div>
        </div>
    </x-card>

    {{-- آخرین فعالیت‌ها --}}
    <x-card shadow>
        <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
            <x-icon name="o-clock" class="w-5 h-5 text-primary" />
            آخرین فعالیت‌ها
            <a href="/activity-log" class="text-xs text-primary hover:underline mr-auto" wire:navigate>مشاهده همه →</a>
        </h3>
        <div class="space-y-2">
            @forelse($this->recentActivities as $activity)
            @php
                $icon = match($activity->type) {
                    'created' => ['o-plus-circle', 'text-success'],
                    'updated' => ['o-pencil-square', 'text-info'],
                    'deleted' => ['o-trash', 'text-error'],
                    'login' => ['o-arrow-right-on-rectangle', 'text-primary'],
                    'logout' => ['o-arrow-left-on-rectangle', 'text-warning'],
                    default => ['o-document-text', 'text-base-content'],
                };
            @endphp
            <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-base-200 transition">
                <x-icon name="{{ $icon[0] }}" class="w-5 h-5 {{ $icon[1] }}" />
                <div class="flex-1 min-w-0">
                    <p class="text-sm truncate">{{ $activity->description }}</p>
                    <p class="text-[10px] text-base-content/50">{{ $activity->user->name ?? 'سیستم' }}</p>
                </div>
                <span class="text-[10px] text-base-content/40 whitespace-nowrap">{{ \Carbon\Carbon::parse($activity->created_at)->diffForHumans() }}</span>
            </div>
            @empty
            <div class="text-center py-8 text-base-content/30">
                <x-icon name="o-clock" class="w-10 h-10 mx-auto mb-2" />
                <p class="text-sm">هنوز فعالیتی ثبت نشده</p>
            </div>
            @endforelse
        </div>
    </x-card>

    @script
    <script>
        const statusLabels = { 'created': 'جدید', 'accepted': 'پذیرفته شده', 'completed': 'تکمیل شده', 'forwarded': 'ارجاع شده', 'rejected': 'رد شده' };
        const statusColors = { 'created': '#3b82f6', 'accepted': '#f59e0b', 'completed': '#22c55e', 'forwarded': '#8b5cf6', 'rejected': '#ef4444' };

        function renderTicketCharts() {
            const trendData = @json($this->ticketChartData);
            const statusData = @json($this->ticketStatusData);
            console.log('Ticket trend data:', trendData);
            console.log('Ticket status data:', statusData);

            const trendContainer = document.getElementById('ticketTrendChart');
            const statusContainer = document.getElementById('ticketStatusChart');

            if (!trendContainer || !statusContainer) {
                console.warn('Chart containers not found in DOM');
                return;
            }

            // نمودار روند تیکت‌ها
            Highcharts.chart(trendContainer, {
                chart: { type: 'areaspline', backgroundColor: 'transparent' },
                title: { text: null },
                credits: { enabled: false },
                xAxis: {
                    categories: trendData.categories.length ? trendData.categories : ['بدون داده'],
                    labels: { style: { fontSize: '10px' } }
                },
                yAxis: { title: { text: 'تعداد' }, min: 0, allowDecimals: false },
                plotOptions: {
                    areaspline: {
                        fillColor: {
                            linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
                            stops: [[0, Highcharts.color('#0ea5e9').setOpacity(0.3).get('rgba')], [1, Highcharts.color('#0ea5e9').setOpacity(0.05).get('rgba')]]
                        },
                        lineColor: '#0ea5e9',
                        marker: { enabled: false }
                    }
                },
                series: [{ name: 'تیکت‌ها', data: trendData.series.length ? trendData.series : [0], color: '#0ea5e9' }],
                legend: { enabled: false }
            });

            // نمودار وضعیت تیکت‌ها
            const pieData = Object.keys(statusData).length
                ? Object.entries(statusData).map(([key, val]) => ({ name: statusLabels[key] || key, y: val, color: statusColors[key] || '#6b7280' }))
                : [{ name: 'بدون داده', y: 0, color: '#6b7280' }];

            Highcharts.chart(statusContainer, {
                chart: { type: 'pie', backgroundColor: 'transparent' },
                title: { text: null },
                credits: { enabled: false },
                plotOptions: {
                    pie: {
                        innerSize: '55%',
                        dataLabels: { enabled: true, format: '<b>{point.name}</b>: {point.y}' }
                    }
                },
                series: [{ name: 'تیکت‌ها', data: pieData }]
            });
        }

        // Livewire lifecycle hooks
        document.addEventListener('livewire:loaded', renderTicketCharts);
        document.addEventListener('livewire:updated', renderTicketCharts);

        // Fallback: if Livewire is already loaded, run immediately
        if (window.Livewire) {
            renderTicketCharts();
        }
    </script>
    @endscript
</div>