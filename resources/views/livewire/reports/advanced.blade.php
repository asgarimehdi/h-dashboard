<?php

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\DailySeries;
use App\Support\JalaliDateRange;
use App\Support\ScopeCacheKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use WithPagination;

    public string $reportType = 'tickets';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public ?int $unitId = null;

    public ?int $parentUnitId = null;

    public ?int $rootUnitId = null;

    public string $statusFilter = 'all';

    public $units = [];

    /** Issue #819 — see the dashboard's `emptyScope` banner for the rationale. */
    public bool $emptyScope = false;

    public function mount(): void
    {
        $this->dateFrom = Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d');
        $this->dateTo = Jalalian::fromCarbon(now())->format('Y/m/d');

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $this->emptyScope = $accessibleIds === [];
        if ($accessibleIds === []) {
            $this->units = [];
        } else {
            $cacheKey = 'advanced_report:units:'.md5(implode(',', $accessibleIds));
            $this->units = Cache::remember($cacheKey, 300, function () use ($accessibleIds) {
                return Unit::whereIn('id', $accessibleIds)
                    ->select('id', 'name', 'parent_id')
                    ->orderBy('name')
                    ->get()
                    ->toArray();
            });
        }
    }

    /**
     * The chosen from..to range as Carbon bounds, or null when either end is
     * missing/invalid or the range is inverted — in which case the daily series
     * has no window to fill.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function dateRange(): ?array
    {
        return JalaliDateRange::range($this->dateFrom, $this->dateTo);
    }

    #[Livewire\Attributes\Computed]
    public function childUnits()
    {
        if (! $this->rootUnitId || empty($this->units)) {
            return collect();
        }

        return collect($this->units)->where('parent_id', $this->rootUnitId)->values();
    }

    #[Livewire\Attributes\Computed]
    public function grandChildUnits()
    {
        if (! $this->parentUnitId || empty($this->units)) {
            return collect();
        }

        return collect($this->units)->where('parent_id', $this->parentUnitId)->values();
    }

    public function updatedRootUnitId(): void
    {
        $this->parentUnitId = null;
        $this->unitId = null;
    }

    public function updatedParentUnitId(): void
    {
        $this->unitId = null;
    }

    /**
     * Issue #915 — the status option list and the predicate whitelist are
     * the same data, keyed by `reportType`. Tickets read their labels from
     * `Ticket::getStatusNameAttribute()`, todos reuse the
     * `reports/todos.blade.php` values, and persons read theirs from
     * `PersonsExport::resolveStatus()` (`active|inactive|retired`).
     *
     * @var array<string, array<string, string>>
     */
    private const STATUS_OPTIONS = [
        'tickets' => [
            'created' => 'جدید',
            'forwarded' => 'ارجاع شده',
            'accepted' => 'در حال پیگیری',
            'completed' => 'پایان یافته',
            'rejected' => 'رد شده',
        ],
        'todos' => [
            'completed' => 'تکمیل شده',
            'pending' => 'در انتظار',
            'overdue' => 'سررسید گذشته',
        ],
        'persons' => [
            'active' => 'فعال',
            'inactive' => 'غیرفعال',
            'retired' => 'بازنشسته',
        ],
    ];

    /**
     * The status options for the current report type. Consumed by both the
     * Blade `@foreach` and the `statusFilter` whitelist, so the rendered
     * select and the applied predicate cannot drift apart.
     *
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return self::STATUS_OPTIONS[$this->reportType] ?? [];
    }

    /**
     * Issue #915 — `statusFilter` is public Livewire state with no
     * validation. An unknown value falls back to `'all'` instead of reaching
     * a column reference. Called at the top of `reportData()` so the
     * assignment persists for the render that follows.
     */
    private function normalizeStatusFilter(): void
    {
        if (! array_key_exists($this->statusFilter, $this->statusOptions())) {
            $this->statusFilter = 'all';
        }
    }

    /**
     * Issue #915 — a value picked under one report type (e.g. a ticket
     * status) must not survive the switch to another type whose predicates
     * mean something else (or nothing at all).
     */
    public function updatedReportType(): void
    {
        $this->statusFilter = 'all';
    }

    /**
     * Issue #915 — apply the status clause for the current report type.
     * Tickets and persons filter their real `status` column; todos map
     * `completed|pending|overdue` onto the `is_completed`/`end_at`
     * predicates from `reports/todos.blade.php:121-123`.
     *
     * @param  EloquentBuilder<covariant Model>|QueryBuilder  $query
     */
    private function applyStatusFilter(EloquentBuilder|QueryBuilder $query): void
    {
        if ($this->statusFilter === 'all') {
            return;
        }

        if ($this->reportType === 'todos') {
            $now = now();

            match ($this->statusFilter) {
                'completed' => $query->where('is_completed', true),
                'pending' => $query->where('is_completed', false)
                    ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now)),
                'overdue' => $query->where('is_completed', false)
                    ->whereNotNull('end_at')->where('end_at', '<', $now),
                default => null,
            };

            return;
        }

        $query->where('status', $this->statusFilter);
    }

    #[Livewire\Attributes\Computed]
    public function reportData(): array
    {
        $this->normalizeStatusFilter();

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Issue #1282 — tickets/todos go through the versioned cache below.
        // The persons branch stays uncached and untouched on purpose: it has
        // no `report_persons` namespace and no invalidation hook yet.
        if ($this->reportType !== 'persons') {
            return $this->rememberReport($accessibleIds);
        }

        $query = Person::whereIn('u_id', $accessibleIds);

        // فیلتر تاریخ (ورودی شمسی)
        if ($from = JalaliDateRange::parse($this->dateFrom)) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = JalaliDateRange::parse($this->dateTo, endOfDay: true)) {
            $query->where('created_at', '<=', $to);
        }

        // فیلتر واحد (سلسله‌مراتبی)
        $unitId = $this->unitId ?? $this->parentUnitId ?? $this->rootUnitId;
        if ($unitId) {
            $descendantIds = Unit::descendantIds($unitId)->toArray();
            $descendantIds[] = $unitId;
            $query->whereIn('u_id', $descendantIds);
        }

        // فیلتر وضعیت — نوع‌آگاه (#915): هر گزینه و predicate‌اش در
        // STATUS_OPTIONS کنار هم زندگی می‌کنند؛ todos ستون status ندارد و
        // روی is_completed/end_at نگاشت می‌شود.
        $this->applyStatusFilter($query);

        $total = $query->count();

        // Issue #736: materialise every day in the chosen range, zeros included,
        // so a day with no rows is a zero rather than a missing column. The
        // filtered $query is reused, so unit/status/date filters still apply.
        $range = $this->dateRange();
        $byDay = $range
            ? array_map(
                fn (array $row) => [
                    'day' => Jalalian::fromCarbon(Carbon::parse($row['day']))->format('Y/m/d'),
                    'count' => $row['count'],
                ],
                DailySeries::between($range[0], $range[1])->counts($query->clone(), 'created_at')
            )
            : [];

        $byUnit = $query->clone()
            ->selectRaw('u_id, count(*) as count')
            ->groupBy('u_id')
            ->with('unit:id,name')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->unit?->name ?? 'نامشخص' => $item->count])
            ->toArray();

        $details = [
            'byEstekhdam' => Person::selectRaw('COALESCE(estekhdams.name, ?) as name, COUNT(*) as count', ['نامشخص'])
                ->leftJoin('estekhdams', 'persons.e_id', '=', 'estekhdams.id')
                ->whereIn('persons.u_id', $accessibleIds)
                ->when($unitId, fn ($q) => $q->whereIn('persons.u_id', $descendantIds))
                ->groupBy('name')
                ->pluck('count', 'name')
                ->toArray(),
            'byTahsil' => Person::selectRaw('COALESCE(tahsils.name, ?) as name, COUNT(*) as count', ['نامشخص'])
                ->leftJoin('tahsils', 'persons.t_id', '=', 'tahsils.id')
                ->whereIn('persons.u_id', $accessibleIds)
                ->when($unitId, fn ($q) => $q->whereIn('persons.u_id', $descendantIds))
                ->groupBy('name')
                ->pluck('count', 'name')
                ->toArray(),
            'bySemat' => Person::selectRaw('COALESCE(semats.name, ?) as name, COUNT(*) as count', ['نامشخص'])
                ->leftJoin('semats', 'persons.s_id', '=', 'semats.id')
                ->whereIn('persons.u_id', $accessibleIds)
                ->when($unitId, fn ($q) => $q->whereIn('persons.u_id', $descendantIds))
                ->groupBy('name')
                ->pluck('count', 'name')
                ->toArray(),
        ];

        return [
            'total' => $total,
            'byDay' => $byDay,
            'byUnit' => $byUnit,
            'details' => $details,
        ];
    }

    /**
     * Issue #1282 — cache the whole tickets/todos payload behind the existing
     * versioned namespace.
     *
     * The filters, the query and `Unit::descendantIds()` all run inside the
     * closure so a cache hit costs zero report queries. The key carries every
     * input that changes the payload: scope hash (namespace version handles
     * Ticket/Todo writes), from/to, the effective unit, the normalized status,
     * and the hierarchy version for unit names and descendants.
     *
     * @param  array<int>  $accessibleIds
     * @return array{total: int, byDay: array<int, array{day: string, count: int}>, byUnit: array<string, int>, details: array<string, int>}
     */
    private function rememberReport(array $accessibleIds): array
    {
        $from = JalaliDateRange::parse($this->dateFrom);
        $to = JalaliDateRange::parse($this->dateTo, endOfDay: true);
        $range = $this->dateRange();
        $unitId = $this->unitId ?? $this->parentUnitId ?? $this->rootUnitId;

        $cache = app(CacheInvalidationServiceInterface::class);

        /** @var array{total: int, byDay: array<int, array{day: string, count: int}>, byUnit: array<string, int>, details: array<string, int>} $data */
        $data = $cache->remember(
            $this->reportType === 'todos' ? 'report_todos' : 'report_tickets',
            ScopeCacheKey::hash($accessibleIds),
            fn (): array => $this->buildReport($accessibleIds, $from, $to, $range, $unitId),
            10,
            [
                'type' => $this->reportType,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'days' => $range ? (int) $range[0]->diffInDays($range[1]) + 1 : null,
                'unit' => $unitId,
                'status' => $this->statusFilter,
                'hierarchy' => $cache->getVersion('unit_hierarchy'),
            ],
        );

        return $data;
    }

    /**
     * Build the uncached tickets/todos payload (the cache-miss path).
     *
     * @param  array<int>  $accessibleIds
     * @param  array{0: Carbon, 1: Carbon}|null  $range
     * @return array{total: int, byDay: array<int, array{day: string, count: int}>, byUnit: array<string, int>, details: array<string, int>}
     */
    private function buildReport(array $accessibleIds, ?Carbon $from, ?Carbon $to, ?array $range, ?int $unitId): array
    {
        $query = $this->reportType === 'todos'
            ? Todo::whereIn('unit_id', $accessibleIds)
            : Ticket::whereIn('unit_id', $accessibleIds);

        // فیلتر تاریخ (ورودی شمسی)
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        // فیلتر واحد (سلسله‌مراتبی)
        if ($unitId) {
            $descendantIds = Unit::descendantIds($unitId)->toArray();
            $descendantIds[] = $unitId;
            $query->whereIn('unit_id', $descendantIds);
        }

        // فیلتر وضعیت — نوع‌آگاه (#915): هر گزینه و predicate‌اش در
        // STATUS_OPTIONS کنار هم زندگی می‌کنند؛ todos ستون status ندارد و
        // روی is_completed/end_at نگاشت می‌شود.
        $this->applyStatusFilter($query);

        // Issue #736: materialise every day in the chosen range, zeros included,
        // so a day with no rows is a zero rather than a missing column. The
        // filtered $query is reused, so unit/status/date filters still apply.
        $byDay = $range
            ? array_map(
                fn (array $row) => [
                    'day' => Jalalian::fromCarbon(Carbon::parse($row['day']))->format('Y/m/d'),
                    'count' => $row['count'],
                ],
                DailySeries::between($range[0], $range[1])->counts($query->clone(), 'created_at')
            )
            : [];

        $byUnit = (clone $query)
            ->selectRaw('unit_id, count(*) as count')
            ->groupBy('unit_id')
            ->with('unit:id,name')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->unit->name ?? 'نامشخص' => $item->count])
            ->toArray();

        if ($this->reportType === 'tickets') {
            // Issue #1282 — total and the four detail buckets in one aggregate.
            // Only the FILTER syntax comes from the dashboard: urgent/normal/low
            // count every status here, and overdue binds PHP `now()`, not SQL
            // `NOW()`.
            $stats = (clone $query)->selectRaw(
                'COUNT(*) AS total, '.
                'COUNT(*) FILTER (WHERE tickets.priority = ?) AS urgent, '.
                'COUNT(*) FILTER (WHERE tickets.priority = ?) AS normal, '.
                'COUNT(*) FILTER (WHERE tickets.priority = ?) AS low, '.
                'COUNT(*) FILTER (WHERE tickets.status IN (?, ?) AND tickets.deadline < ?) AS overdue',
                ['urgent', 'normal', 'low', 'created', 'forwarded', now()],
            )->toBase()->first();

            $total = (int) ($stats->total ?? 0);
            $details = [
                'urgent' => (int) ($stats->urgent ?? 0),
                'normal' => (int) ($stats->normal ?? 0),
                'low' => (int) ($stats->low ?? 0),
                'overdue' => (int) ($stats->overdue ?? 0),
            ];
        } else {
            $total = $query->count();
            $details = [];
        }

        return [
            'total' => $total,
            'byDay' => $byDay,
            'byUnit' => $byUnit,
            'details' => $details,
        ];
    }

    /**
     * Fresh chart data for JS (wire:navigate + filter updates).
     *
     * @return array{total: int, byDay: array, byUnit: array, details: array}
     */
    public function chartPayload(): array
    {
        return $this->reportData;
    }
}; ?>
<div>
    {{-- Issue #1042: server-rendered chart aggregates. The @script below reads
        this (morphed on every render) instead of firing a second method request
        per filter change. --}}
    <div id="advancedChartData" class="hidden" data-chart='@json(['byDay' => $this->reportData['byDay'], 'byUnit' => $this->reportData['byUnit'], 'details' => $this->reportData['details']])'></div>
    <x-header title="گزارش تیکت‌ها" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    @if($emptyScope)
        <div class="alert alert-info mb-6" role="alert">
            <x-icon name="o-information-circle" class="w-5 h-5" />
            <span>واحدی برای نمایش انتخاب نشده</span>
        </div>
    @endif

    {{-- فیلترها --}}
    <x-card shadow class="mb-6">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div>
                <label class="font-bold text-xs">نوع گزارش</label>
                <select class="select select-bordered select-sm w-full" wire:model.live="reportType">
                    <option value="tickets">تیکت‌ها</option>
                    <option value="todos">وظایف</option>
                    <option value="persons">پرسنل</option>
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">واحد اصلی</label>
                <select class="select select-bordered select-sm w-full" wire:model.live="rootUnitId">
                    <option value="">همه</option>
                    @foreach($units as $u)
                    <option value="{{ $u['id'] }}">{{ $u['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">واحد فرعی</label>
                <select class="select select-bordered select-sm w-full" wire:model.live="parentUnitId">
                    <option value="">همه</option>
                    @foreach($this->childUnits as $u)
                    <option value="{{ $u['id'] }}">{{ $u['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">واحد</label>
                <select class="select select-bordered select-sm w-full" wire:model.live="unitId">
                    <option value="">همه</option>
                    @foreach($this->grandChildUnits as $u)
                    <option value="{{ $u['id'] }}">{{ $u['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">وضعیت</label>
                <select class="select select-bordered select-sm w-full" wire:model.live="statusFilter">
                    <option value="all">همه</option>
                    @foreach($this->statusOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3 mt-3">
            <div class="flex flex-col gap-1" wire:ignore>
                <label class="font-bold text-xs">از تاریخ</label>
                <input data-jdp id="advanced_report_date_from" placeholder="از تاریخ"
                    value="{{ $dateFrom }}"
                    class="input input-bordered input-sm w-full text-center cursor-pointer" readonly>
            </div>
            <div class="flex flex-col gap-1" wire:ignore>
                <label class="font-bold text-xs">تا تاریخ</label>
                <input data-jdp id="advanced_report_date_to" placeholder="تا تاریخ"
                    value="{{ $dateTo }}"
                    class="input input-bordered input-sm w-full text-center cursor-pointer" readonly>
            </div>
        </div>
    </x-card>

    {{-- آمار --}}
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">کل</div>
            <div class="stat-value text-lg text-primary">{{ $this->reportData['total'] }}</div>
        </div>
        @if($this->reportType === 'tickets' && count($this->reportData['details']) > 0)
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-error">فوری</div>
            <div class="stat-value text-lg text-error">{{ $this->reportData['details']['urgent'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-warning">عادی</div>
            <div class="stat-value text-lg text-warning">{{ $this->reportData['details']['normal'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-error">سررسید گذشته</div>
            <div class="stat-value text-lg text-error">{{ $this->reportData['details']['overdue'] }}</div>
        </div>
        @endif
        @if($this->reportType === 'persons' && count($this->reportData['details']) > 0)
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">تعداد استخدام</div>
            <div class="stat-value text-lg text-info">{{ count($this->reportData['details']['byEstekhdam'] ?? []) }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">تعداد تحصیلات</div>
            <div class="stat-value text-lg text-success">{{ count($this->reportData['details']['byTahsil'] ?? []) }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">تعداد سمت</div>
            <div class="stat-value text-lg text-warning">{{ count($this->reportData['details']['bySemat'] ?? []) }}</div>
        </div>
        @endif
    </div>

    {{-- نمودارها --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-card shadow>
            <h3 class="font-bold mb-4">روند روزانه</h3>
            <div id="trendChart" wire:ignore style="height: 300px;"></div>
        </x-card>

        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
            <div id="unitChart" wire:ignore style="height: 300px;"></div>
        </x-card>
    </div>

    {{-- نمودارهای توزیع پرسنل --}}
    @if($reportType === 'persons')
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
        <x-card shadow>
            <h3 class="font-bold mb-4">توزيع بر اساس استخدام</h3>
            <div id="estekhdamChart" wire:ignore style="height: 300px;"></div>
        </x-card>
        <x-card shadow>
            <h3 class="font-bold mb-4">توزيع بر اساس تحصیلات</h3>
            <div id="tahsilChart" wire:ignore style="height: 300px;"></div>
        </x-card>
        <x-card shadow>
            <h3 class="font-bold mb-4">توزيع بر اساس سمت</h3>
            <div id="sematChart" wire:ignore style="height: 300px;"></div>
        </x-card>
    </div>
    @endif
</div>

@assets
    {{-- Issue #1182: jalaliDatepicker is page-specific and used to ship in the
         global layout on every page. `@assets` keeps the tag in <head>, so the
         library is defined before this page's first `startWatch()` call — on a
         full load and before the swap on `wire:navigate`. --}}
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
@endassets

@assets
<script src="{{ asset('js/chart/highcharts.js') }}"></script>
<script src="{{ asset('js/chart/accessibility.js') }}"></script>
@endassets

@script
<script>
    function waitForHighcharts(callback, attempts = 0) {
        if (typeof window.Highcharts !== 'undefined') {
            callback();
            return;
        }
        if (attempts > 100) {
            console.error('Highcharts failed to load');
            return;
        }
        setTimeout(() => waitForHighcharts(callback, attempts + 1), 50);
    }

    function destroyChartById(id) {
        const container = document.getElementById(id);
        if (!container || typeof Highcharts === 'undefined') {
            return;
        }
        const existing = Highcharts.charts?.find(c => c && c.renderTo === container);
        if (existing) {
            existing.destroy();
        }
        container.innerHTML = '';
    }

    function renderCharts() {
        waitForHighcharts(() => {
            // Issue #1042: read the server-rendered aggregates above — the view
            // already ran reportData for this render, so a second method request
            // would just re-run it.
            const el = document.getElementById('advancedChartData');
            const reportData = el ? JSON.parse(el.dataset.chart) : {};

            destroyChartById('trendChart');
            destroyChartById('unitChart');
            destroyChartById('estekhdamChart');
            destroyChartById('tahsilChart');
            destroyChartById('sematChart');

            if (reportData.byDay && reportData.byDay.length > 0) {
                Highcharts.chart('trendChart', {
                    chart: { type: 'areaspline' },
                    title: { text: '' },
                    xAxis: { categories: reportData.byDay.map(d => d.day) },
                    yAxis: { title: { text: 'تعداد' } },
                    series: [{
                        name: 'تعداد',
                        data: reportData.byDay.map(d => d.count),
                        color: '#6366f1',
                        fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(99,102,241,0.3)'], [1, 'rgba(99,102,241,0)']] }
                    }],
                    credits: { enabled: false }
                });
            }

            const unitLabels = Object.keys(reportData.byUnit || {});
            const unitValues = Object.values(reportData.byUnit || {});
            if (unitLabels.length > 0) {
                Highcharts.chart('unitChart', {
                    chart: { type: 'pie' },
                    title: { text: '' },
                    series: [{ name: 'تعداد', data: unitLabels.map((label, i) => ({ name: label, y: unitValues[i] })) }],
                    credits: { enabled: false }
                });
            }

            const details = reportData.details || {};
            const pieColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316'];

            function renderPieChart(containerId, data) {
                const labels = Object.keys(data || {});
                const values = Object.values(data || {});
                if (labels.length > 0) {
                    Highcharts.chart(containerId, {
                        chart: { type: 'pie' },
                        title: { text: '' },
                        series: [{
                            name: 'تعداد',
                            data: labels.map((label, i) => ({ name: label, y: values[i], color: pieColors[i % pieColors.length] }))
                        }],
                        credits: { enabled: false }
                    });
                }
            }

            renderPieChart('estekhdamChart', details.byEstekhdam);
            renderPieChart('tahsilChart', details.byTahsil);
            renderPieChart('sematChart', details.bySemat);
        });
    }

    // Full page load + wire:navigate
    renderCharts();

    // Re-render after filter updates
    // Issue #1042: the report-type and unit selects re-render through the DOM
    // payload (morphed on each server render) instead of a second
    // `chartPayload()` request. The date watchers stay because the Jalali
    // datepicker writes the properties via `$wire.set`.
    $wire.$watch('dateFrom', () => renderCharts());
    $wire.$watch('dateTo', () => renderCharts());
    Livewire.hook('morphed', ({ component }) => {
        if (component.id === $wire.$id) renderCharts();
    });

    const initAdvancedReportJdp = () => {
        if (typeof jalaliDatepicker === 'undefined') {
            return;
        }

        jalaliDatepicker.startWatch({
            time: false,
            hasSecond: false,
            format: 'YYYY/MM/DD',
            separatorChars: { date: '/', between: ' ', time: ':' },
        });

        const fromInput = document.getElementById('advanced_report_date_from');
        const toInput = document.getElementById('advanced_report_date_to');

        if (fromInput && !fromInput.dataset.jdpBound) {
            fromInput.dataset.jdpBound = '1';
            fromInput.addEventListener('jdp:change', e => {
                $wire.set('dateFrom', e.target.value);
            });
        }
        if (toInput && !toInput.dataset.jdpBound) {
            toInput.dataset.jdpBound = '1';
            toInput.addEventListener('jdp:change', e => {
                $wire.set('dateTo', e.target.value);
            });
        }
    };

    initAdvancedReportJdp();
    document.addEventListener('livewire:navigated', initAdvancedReportJdp);
</script>
@endscript