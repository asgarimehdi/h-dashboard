<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Services\AccessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Morilog\Jalali\Jalalian;

class ReportController extends Controller
{
    public function units(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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

        return response()->json([
            'total' => $total,
            'with_boundary' => $withBoundary,
            'without_boundary' => $withoutBoundary,
            'by_type' => $byType,
        ]);
    }

    public function todos(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $version = Cache::get('report_todos_version', 0);
        $cacheKey = "report_todos:v{$version}:" . md5(json_encode($accessibleIds));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds) {
            $now = now();

            // Single query: completed/pending/overdue via conditional aggregation (was 3 count queries)
            $stats = Todo::whereIn('unit_id', $accessibleIds)
                ->selectRaw(
                    'SUM(CASE WHEN is_completed THEN 1 ELSE 0 END) as completed, '
                    . 'SUM(CASE WHEN NOT is_completed THEN 1 ELSE 0 END) as pending, '
                    . 'SUM(CASE WHEN NOT is_completed AND end_at IS NOT NULL AND end_at < ? THEN 1 ELSE 0 END) as overdue',
                    [$now]
                )
                ->first();

            $byDay = Todo::whereIn('unit_id', $accessibleIds)
                ->selectRaw("date(start_at) as day, count(*) as count")
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(fn ($r) => [
                    'day' => Jalalian::fromCarbon(Carbon::parse($r->day))->format('Y/m/d'),
                    'count' => (int) $r->count,
                ])
                ->toArray();

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
        });

        return response()->json($data);
    }

    public function tickets(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());
        $version = Cache::get('report_tickets_version', 0);
        $cacheKey = "report_tickets:v{$version}:" . md5(json_encode($accessibleIds));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessibleIds) {
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

            $byDay = (clone $query)
                ->selectRaw("date(created_at) as day, count(*) as count")
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(fn ($r) => [
                    'day' => Jalalian::fromCarbon(Carbon::parse($r->day))->format('Y/m/d'),
                    'count' => (int) $r->count,
                ])
                ->toArray();

            return [
                'total' => array_sum($byStatus),
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
                'by_day' => $byDay,
            ];
        });

        return response()->json($data);
    }
}