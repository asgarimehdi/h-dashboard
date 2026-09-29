<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Rules\ReportDays;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\DailySeries;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Morilog\Jalali\Jalalian;

class ReportController extends Controller
{
    public function __construct(
        protected CacheInvalidationServiceInterface $cache
    ) {}

    public function units(UnitScopedRequest $request): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();
        $scopeHash = md5(json_encode($accessibleIds));

        $data = $this->cache->remember('report_units', $scopeHash, function () use ($accessibleIds) {
            // Single query: total + with_boundary via conditional aggregation (was 2 count queries)
            $stats = Unit::whereIn('id', $accessibleIds)
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN boundary_id IS NOT NULL THEN 1 ELSE 0 END) as with_boundary')
                ->first();

            $total = (int) $stats->total;
            $withBoundary = (int) $stats->with_boundary;
            $withoutBoundary = $total - $withBoundary;

            $byType = Unit::query()
                ->selectRaw('COALESCE(unit_types.name, ?) as type_name, COUNT(units.id) as count', ['نامشخص'])
                ->leftJoin('unit_types', 'units.unit_type_id', '=', 'unit_types.id')
                ->whereIn('units.id', $accessibleIds)
                ->groupBy('type_name')
                ->pluck('count', 'type_name')
                ->toArray();

            return [
                'total' => $total,
                'with_boundary' => $withBoundary,
                'without_boundary' => $withoutBoundary,
                'by_type' => $byType,
            ];
        }, 10);

        return response()->json($data);
    }

    public function todos(UnitScopedRequest $request): JsonResponse
    {
        $days = $this->days($request);
        $accessibleIds = $request->accessibleIds();
        $scopeHash = md5(json_encode($accessibleIds));

        $data = $this->cache->remember('report_todos', $scopeHash, function () use ($accessibleIds, $days) {
            $now = now();
            $query = Todo::whereIn('unit_id', $accessibleIds);

            // Single query: completed/pending/overdue via conditional aggregation (was 3 count queries)
            $stats = (clone $query)
                ->selectRaw(
                    'SUM(CASE WHEN is_completed THEN 1 ELSE 0 END) as completed, '
                    .'SUM(CASE WHEN NOT is_completed THEN 1 ELSE 0 END) as pending, '
                    .'SUM(CASE WHEN NOT is_completed AND end_at IS NOT NULL AND end_at < ? THEN 1 ELSE 0 END) as overdue',
                    [$now]
                )
                ->first();

            $byDay = $this->dailySeries($days, $query, 'start_at');

            $byUnit = Todo::selectRaw('COALESCE(units.name, ?) as unit_name, COUNT(*) as count', ['نامشخص'])
                ->whereIn('todos.unit_id', $accessibleIds)
                ->leftJoin('units', 'todos.unit_id', '=', 'units.id')
                ->groupBy('unit_name')
                ->pluck('count', 'unit_name')
                ->toArray();

            return [
                'completed' => (int) $stats->completed,
                'pending' => (int) $stats->pending,
                'overdue' => (int) $stats->overdue,
                'by_day' => $byDay,
                'by_unit' => $byUnit,
            ];
        }, 10, ['days' => $days]);

        return response()->json($data);
    }

    public function tickets(UnitScopedRequest $request): JsonResponse
    {
        $days = $this->days($request);
        $accessibleIds = $request->accessibleIds();
        $scopeHash = md5(json_encode($accessibleIds));

        $data = $this->cache->remember('report_tickets', $scopeHash, function () use ($accessibleIds, $days) {
            $query = Ticket::whereIn('unit_id', $accessibleIds);

            $byStatus = (clone $query)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $byPriority = (clone $query)
                ->selectRaw('priority, count(*) as count')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            $byDay = $this->dailySeries($days, $query, 'created_at');

            return [
                'total' => array_sum($byStatus),
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
                'by_day' => $byDay,
            ];
        }, 10, ['days' => $days]);

        return response()->json($data);
    }

    /**
     * Resolve and validate the `?days=` window (default 30, cap 365).
     *
     * The UI and the API used to disagree about what a daily chart covered —
     * the UI clipped to 30 while the API aggregated the whole history — so both
     * now take the window from here.
     */
    private function days(UnitScopedRequest $request): int
    {
        $request->validate(['days' => ['sometimes', new ReportDays]]);

        return DailySeries::resolveDays($request->query('days'));
    }

    /**
     * Daily counts as Jalali labels, one entry per day in the window.
     *
     * @param  EloquentBuilder<covariant Model>  $query  Already scoped to the caller's units
     * @return array<int, array{day: string, count: int}>
     */
    private function dailySeries(int $days, EloquentBuilder $query, string $dateColumn): array
    {
        return array_map(
            fn (array $row) => [
                'day' => Jalalian::fromCarbon(Carbon::parse($row['day']))->format('Y/m/d'),
                'count' => $row['count'],
            ],
            DailySeries::lastDays($days)->counts($query, $dateColumn)
        );
    }
}
