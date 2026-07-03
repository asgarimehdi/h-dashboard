<?php

namespace App\Http\Controllers;

use App\Models\{Ticket, Todo, ActivityLog, Notification};
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};

class ToolsController extends Controller
{
    /**
     * Show tools page
     */
    public function index()
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        
        $stats = [
            'old_tickets' => Ticket::whereIn('unit_id', $accessibleIds)
                ->where('status', 'completed')
                ->where('completed_at', '<', now()->subDays(30))
                ->count(),
            'old_activities' => ActivityLog::where('created_at', '<', now()->subDays(90))->count(),
            'old_notifications' => Notification::where('created_at', '<', now()->subDays(7))->count(),
            'total_tickets' => Ticket::whereIn('unit_id', $accessibleIds)->count(),
            'total_activities' => ActivityLog::count(),
            'total_notifications' => Notification::count(),
        ];

        return view('tools.index', compact('stats'));
    }

    /**
     * Archive old completed tickets (older than X days)
     */
    public function archiveTickets(Request $request)
    {
        $request->validate(['days' => 'required|integer|min:7|max:365']);

        $count = Ticket::whereIn('unit_id', app(AccessService::class)->accessibleUnitIds())
            ->where('status', 'completed')
            ->where('completed_at', '<', now()->subDays($request->days))
            ->update(['status' => 'archived']);

        return redirect()->back()->with('success', "{$count} تیکت قدیمی آرشیو شد.");
    }

    /**
     * Clean old activity logs
     */
    public function cleanActivities(Request $request)
    {
        $request->validate(['days' => 'required|integer|min:30|max:365']);

        $count = ActivityLog::where('created_at', '<', now()->subDays($request->days))->delete();

        return redirect()->back()->with('success', "{$count} لاگ قدیمی پاک شد.");
    }

    /**
     * Clean old notifications
     */
    public function cleanNotifications(Request $request)
    {
        $request->validate(['days' => 'required|integer|min:1|max:90']);

        $count = Notification::where('created_at', '<', now()->subDays($request->days))->delete();

        return redirect()->back()->with('success', "{$count} اعلان قدیمی پاک شد.");
    }
}