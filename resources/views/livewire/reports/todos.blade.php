<?php

use App\Models\Todo;
use App\Services\AccessService;
use App\Services\DailySeries;
use App\Support\JalaliDateRange;
use App\Traits\ClampsPageSize;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use ClampsPageSize;
    use WithPagination;

    /** Issue #1024 — the list is paginated instead of hydrated whole. */
    public int $perPage = 25;

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $selectedUnitId = null;

    public ?string $statusFilter = null; // completed, pending, overdue, null=all

    public bool $showHelpModal = false;

    /** Issue #819 — see the dashboard's `emptyScope` banner for the rationale. */
    public bool $emptyScope = false;

    // Issue #1632: the route gate (`role_or_permission:manage_personnel`) runs only
    // on the full page load, never on `/livewire/update`, so it is re-checked here.
    public function boot(): void
    {
        $this->authorize('manage_personnel');
    }

    public function mount(): void
    {
        $this->dateFrom = Jalalian::fromCarbon(now()->subDays(30))->format('Y/m/d');
        $this->dateTo = Jalalian::fromCarbon(now()->addDays(30))->format('Y/m/d');

        $this->emptyScope = app(AccessService::class)->accessibleUnitIds() === [];
    }

    /**
     * Issue #1024: a filter change starts a new result set, so the list goes
     * back to page 1. The Jalali datepicker writes `dateFrom`/`dateTo` through
     * `$wire.set`, which lands here too.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['selectedUnitId', 'statusFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    /**
     * The chosen from..to range as Carbon bounds, or null when either end is
     * missing/invalid — in which case the daily series has no window to fill.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function dateRange(): ?array
    {
        return JalaliDateRange::range($this->dateFrom, $this->dateTo);
    }

    public function chartPayload(): array
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Issue #819 / #1872: unconditional, fail-closed scope predicate that
        // also honours the null-unit contract (#838) — a unit-less todo belongs
        // to its creator. `accessibleTo()` wraps the predicate in its own
        // `where(function…)` on the Eloquent builder, so `(clone $query)->with()`
        // stays on the Eloquent builder and an empty scope still compiles to
        // `0 = 1`.
        $query = Todo::accessibleTo($accessibleIds, auth()->id())
            ->when($this->selectedUnitId, fn ($q) => $q->where('unit_id', $this->selectedUnitId));

        // #1942 — window membership is overlap, not "starts inside": a todo
        // that began before `from` but is still live in the window belongs to
        // the report, and so does one that runs past `to`. `end_at IS NULL` is
        // open-ended (#1792), never out of range. The upper bound sits on
        // `start_at` only — a todo starting after `to` is outside the window.
        if ($from = JalaliDateRange::parse($this->dateFrom)) {
            $query->where(function ($q) use ($from) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', $from);
            });
        }
        if ($to = JalaliDateRange::parse($this->dateTo, endOfDay: true)) {
            $query->where('start_at', '<=', $to);
        }

        $aggregates = $this->aggregatePayload($accessibleIds, $query);

        $now = now();

        // Issue #1024: only the row list is paginated — `completed`/`pending`/
        // `overdue`/`byUnit`/`byDay` are computed from their own unpaginated
        // clones, so they still describe the whole filtered set.
        $itemsPage = (clone $query)
            ->when($this->statusFilter === 'completed', fn ($q) => $q->where('is_completed', true))
            ->when($this->statusFilter === 'pending', fn ($q) => $q->where('is_completed', false)->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now)))
            ->when($this->statusFilter === 'overdue', fn ($q) => $q->where('is_completed', false)->whereNotNull('end_at')->where('end_at', '<', $now))
            ->with('unit:id,name')
            ->orderBy('start_at', 'desc')
            ->paginate($this->pageSize());

        $items = $itemsPage->getCollection()
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'unit' => $t->unit?->name ?? '—',
                'start' => $t->start_at ? Jalalian::fromCarbon(Carbon::parse($t->start_at))->format('Y/m/d') : '—',
                'end' => $t->end_at ? Jalalian::fromCarbon(Carbon::parse($t->end_at))->format('Y/m/d') : '—',
                'completed' => $t->is_completed,
                'is_overdue' => ! $t->is_completed && $t->end_at && $t->end_at < $now,
            ])
            ->all();

        return [
            ...$aggregates,
            // `items` stays a plain array (the fail-closed security test and the
            // `empty()` check in the view both treat it as one); the paginator
            // rides alongside for `->hasPages()`/`->links()`.
            'items' => $items,
            'itemsPaginator' => $itemsPage,
        ];
    }

    /**
     * Issue #1821 — the aggregates are the expensive half of the payload and do
     * not depend on the page, so they are cached for 10 minutes like the
     * advanced report (#1282). The paginator and its rows stay live in
     * chartPayload(); only this slice is frozen.
     *
     * `statusFilter` is deliberately NOT in the key: it only narrows the
     * paginated rows (#1024), never these aggregates. The key does carry the
     * `unit_hierarchy` generation because `byUnit` joins `units.name` and a Unit
     * write bumps only `report_units`, never `report_todos` (#1821). `site` is
     * mandatory — an empty extras array hashes to `'none'`.
     *
     * `overdue` depends on `now()`, so it can be at most 10 minutes behind —
     * the same freshness contract the advanced report accepts (advanced:203).
     *
     * @param  array<int>  $accessibleIds
     * @param  \Illuminate\Database\Eloquent\Builder<Todo>  $query
     * @return array{completed: int, pending: int, overdue: int, byUnit: array<string, int>, byDay: array<int, array{day: string, count: int}>, byStatus: array<string, int>}
     */
    private function aggregatePayload(array $accessibleIds, $query): array
    {
        $cache = app(\App\Services\CacheInvalidationServiceInterface::class);

        /** @var array{completed: int, pending: int, overdue: int, byUnit: array<string, int>, byDay: array<int, array{day: string, count: int}>, byStatus: array<string, int>} $data */
        $data = $cache->remember(
            'report_todos',
            \App\Support\ScopeCacheKey::hash($accessibleIds),
            function () use ($query): array {
                $now = now();
                $completed = (clone $query)->where('is_completed', true)->count();
                $pending = (clone $query)->where('is_completed', false)->count();
                $overdue = (clone $query)->where('is_completed', false)
                    ->whereNotNull('end_at')->where('end_at', '<', $now)->count();

                // #1858 — the unit bars describe the same window as the totals:
                // cloning the filtered query carries the scope, `selectedUnitId`
                // and #1792's open-ended `end_at` arm in one place, so a new
                // filter on `$query` reaches `byUnit` for free. `leftJoin`, never
                // `join`: a todo whose unit row is missing still lands in
                // «نامشخص» instead of silently dropping out of the sum.
                //
                // #1872 — `$query` starts from `Todo::accessibleTo()`, so the
                // null-unit arm (a unit-less todo belongs to its creator) is
                // already part of the clone; no second predicate is needed here.
                $byUnit = (clone $query)
                    ->leftJoin('units', 'todos.unit_id', '=', 'units.id')
                    ->selectRaw('COALESCE(units.name, ?) as unit_name, COUNT(*) as count', ['نامشخص'])
                    ->groupBy('unit_name')
                    ->pluck('count', 'unit_name')
                    ->toArray();

                // Issue #736: materialise every day in the chosen range, zeros
                // included, so a day with no todos is a zero rather than a
                // missing column. The filtered $query is reused, so unit/date
                // filters still apply.
                $range = $this->dateRange();
                $byDay = $range
                    ? array_map(
                        fn (array $row) => [
                            'day' => Jalalian::fromCarbon(Carbon::parse($row['day']))->format('Y/m/d'),
                            'count' => $row['count'],
                        ],
                        DailySeries::between($range[0], $range[1])->counts(clone $query, 'start_at')
                    )
                    : [];

                return [
                    'completed' => $completed,
                    'pending' => $pending,
                    'overdue' => $overdue,
                    'byUnit' => $byUnit,
                    'byDay' => $byDay,
                    'byStatus' => [
                        'تکمیل شده' => $completed,
                        'در انتظار' => $pending,
                        'سررسید گذشته' => $overdue,
                    ],
                ];
            },
            10,
            [
                'site' => 'reports_todos_payload',
                'unit' => $this->selectedUnitId,
                'from' => $this->dateFrom,
                'to' => $this->dateTo,
                // #1942 — the window predicate became overlap; nothing bumps the
                // version on deploy, so the key changes to keep a pre-fix payload
                // out of the 10-minute TTL (same precedent as #1794).
                'window' => 'overlap',
                'hierarchy' => $cache->getVersion('unit_hierarchy'),
                // #1858 — `byUnit` moved from all-time to windowed and nothing
                // bumps the version on deploy; without this discriminator the
                // pre-fix payload survives the 10-minute TTL (#1794 precedent).
                'byunit' => 'windowed',
                // #1872 — null-unit todos are per-user, so the cached aggregate
                // must not be shared across users with the same unit scope.
                'uid' => auth()->id(),
            ],
        );

        return $data;
    }

    public function getAccessibleUnitsProperty()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Issue #1024: a `<select>` needs nothing but id/name.
        return \App\Models\Unit::whereIn('id', $accessibleIds)->get(['id', 'name']);
    }
}; ?>

<div class="p-6" dir="rtl">
    @php $chart = $this->chartPayload(); @endphp
    {{-- Issue #1042: server-rendered chart aggregates. resources/js/report-charts.js reads
        this (morphed on every render) instead of firing a second method request
        per filter change. --}}
    <div id="todosChartData" class="hidden" data-chart='@json(['byStatus' => $chart['byStatus'], 'byUnit' => $chart['byUnit']])'></div>
    <x-header title="گزارش وظایف" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="reports" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" section="reports" />

    @if($emptyScope)
        <div class="alert alert-info mb-6" role="alert">
            <x-icon name="o-information-circle" class="w-5 h-5" />
            <span>واحدی برای نمایش انتخاب نشده</span>
        </div>
    @endif

    {{-- فیلترها --}}
    <x-card shadow class="mb-6">
        <div class="flex gap-3 flex-wrap items-end">
            <div>
                <label class="font-bold text-xs">واحد</label>
                <select class="select select-bordered select-sm" wire:model.live="selectedUnitId">
                    <option value="">همه واحدها</option>
                    @foreach($this->accessibleUnits as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-xs">وضعیت</label>
                <select class="select select-bordered select-sm" wire:model.live="statusFilter">
                    <option value="">همه</option>
                    <option value="completed">تکمیل شده</option>
                    <option value="pending">در انتظار</option>
                    <option value="overdue">سررسید گذشته</option>
                </select>
            </div>
            <div class="flex flex-col gap-1" wire:ignore>
                <label class="font-bold text-xs">از تاریخ شروع</label>
                <input data-jdp id="todo_date_from" placeholder="از تاریخ"
                    value="{{ $dateFrom }}"
                    class="input input-bordered input-sm w-40 text-center cursor-pointer" readonly>
            </div>
            <div class="flex flex-col gap-1" wire:ignore>
                <label class="font-bold text-xs">تا تاریخ پایان</label>
                <input data-jdp id="todo_date_to" placeholder="تا تاریخ"
                    value="{{ $dateTo }}"
                    class="input input-bordered input-sm w-40 text-center cursor-pointer" readonly>
            </div>
        </div>
    </x-card>

    {{-- آمار --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">تکمیل شده</div>
            <div class="stat-value text-lg text-success">{{ $chart['completed'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">در انتظار</div>
            <div class="stat-value text-lg text-warning">{{ $chart['pending'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs text-error">سررسید گذشته</div>
            <div class="stat-value text-lg text-error">{{ $chart['overdue'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">نسبت تکمیل</div>
            @php $total = $chart['completed'] + $chart['pending']; @endphp
            <div class="stat-value text-lg text-primary">{{ $total > 0 ? round($chart['completed'] / $total * 100) : 0 }}%</div>
        </div>
    </div>

    {{-- نمودارها --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس وضعیت</h3>
            <div id="statusChart" wire:ignore class="h-[250px]"></div>
        </x-card>
        <x-card shadow>
            <h3 class="font-bold mb-4">توزیع بر اساس واحد</h3>
            <div id="unitChart" wire:ignore class="h-[250px]"></div>
        </x-card>
    </div>

    {{-- جدول --}}
    <x-card shadow>
        <h3 class="font-bold mb-4">لیست وظایف</h3>
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <th>واحد</th>
                        <th>شروع</th>
                        <th>پایان</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($chart['items'] as $t)
                    <tr class="{{ $t['is_overdue'] ? 'text-error' : '' }}">
                        <td>{{ $t['id'] }}</td>
                        <td>{{ $t['title'] }}</td>
                        <td>{{ $t['unit'] }}</td>
                        <td>{{ $t['start'] }}</td>
                        <td>{{ $t['end'] }}</td>
                        <td>
                            @if($t['completed'])
                                <span class="badge badge-success badge-sm">تکمیل</span>
                            @elseif($t['is_overdue'])
                                <span class="badge badge-error badge-sm">گذشته</span>
                            @else
                                <span class="badge badge-warning badge-sm">در انتظار</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    @if(empty($chart['items']))
                    <tr>
                        <td colspan="6" class="text-center text-base-content/40">موردی یافت نشد</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- Issue #1024: the hand-rolled table has no `with-pagination`, so the
            paginator rides alongside the payload. --}}
        @if ($chart['itemsPaginator']->hasPages())
            {{-- `mary-table-pagination` is the app's own pagination style hook
                (`resources/css/app.css`); the hand-rolled table cannot pass it
                through `x-table`, so it is applied by hand. --}}
            <div class="mt-4 mary-table-pagination">
                {{ $chart['itemsPaginator']->links() }}
            </div>
        @endif
    </x-card>
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
@endassets

