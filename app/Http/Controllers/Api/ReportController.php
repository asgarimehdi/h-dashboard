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
use Morilog\Jalali\Jalalian;

class ReportController extends Controller
{
    public function units(): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $query = Unit::whereIn('id', $accessibleIds);

        $total = $query->count();
        $withBoundary = (clone $query)->whereNotNull('boundary_id')->count();
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
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $query = Todo::whereIn('unit_id', $accessibleIds);

        $now = now();
        $completed = (clone $query)->where('is_completed', true)->count();
        $pending = (clone $query)->where('is_completed', false)->count();
        $overdue = (clone $query)->where('is_completed', false)
            ->whereNotNull('end_at')->where('end_at', '<', $now)->count();

        $byDay = (clone $query)
            ->selectRaw("date(start_at) as day, count(*) as count")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => Jalalian::fromCarbon(Carbon::parse($r->day))->format('Y/m/d'),
                'count' => (int) $r->count,
            ])
            ->toArray();

        $byUnit = (clone $query)
            ->with('unit:id,name')
            ->get()
            ->groupBy(fn ($t) => $t->unit?->name ?? 'نامشخص')
            ->map(fn ($items) => $items->count())
            ->toArray();

        return response()->json([
            'completed' => $completed,
            'pending' => $pending,
            'overdue' => $overdue,
            'by_day' => $byDay,
            'by_unit' => $byUnit,
        ]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $query = Ticket::whereIn('unit_id', $accessibleIds);

        $total = (clone $query)->count();
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

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'by_day' => $byDay,
        ]);
    }
}
