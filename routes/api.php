<?php

use App\Http\Controllers\Api\GisController;
use App\Http\Controllers\Api\HardwareAuditController;
use App\Http\Controllers\Api\HardwareController;
use App\Http\Controllers\Api\HrAnalyticsController;
use App\Http\Controllers\Api\HrStatsController;
use App\Http\Controllers\Api\MultiLatestValueController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrgChartController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TicketCommentController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\TrafficController;
use App\Http\Controllers\Api\UnitController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Login route — stricter rate limit
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'n_code' => 'required',
        'password' => 'required',
    ]);

    $user = User::where('n_code', $credentials['n_code'])->first();

    // Constant-time comparison with dummy hash for non-existent users
    static $dummyHash = null;
    if ($dummyHash === null) {
        $dummyHash = Hash::make(Str::random(32));
    }
    $userHash = $user ? $user->password : $dummyHash;
    $passwordMatches = Hash::check($credentials['password'], $userHash);

    if (! $user || ! $passwordMatches) {
        return response()->json(['message' => 'Credentials not match'], 401);
    }

    $abilities = [
        'units:read',
        'hardware:read', 'hardware:write',
        'tickets:read', 'tickets:write',
        'persons:read', 'persons:write',
        'todos:read', 'todos:write',
        'hr:read',
        'notifications:read',
        'gis:read',
        'reports:read',
        'traffic:read',
    ];

    $token = $user->createToken('flutter-app', $abilities)->plainTextToken;

    return response()->json(['token' => $token]);
})->middleware('throttle:5,1');

// Authenticated routes — global rate limit: 60 req/min
Route::middleware(['auth:sanctum', 'throttle:api-user'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Unit API routes — ability:units:read (Issue #690)
    Route::middleware('ability:units:read')->group(function () {
        Route::get('/units', [UnitController::class, 'index']);
        Route::get('/units/{unit}', [UnitController::class, 'show']);
        Route::middleware(['abilities:units:write', 'role_or_permission:organization'])->group(function () {
            Route::post('/units', [UnitController::class, 'store']);
            Route::put('/units/{unit}', [UnitController::class, 'update']);
            Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
        });
    });

    // Zabbix / Traffic API routes — ability:traffic:read (Issue #690)
    Route::middleware('ability:traffic:read')->group(function () {
        Route::get('/zabbix/traffic', [TrafficController::class, 'index']);
        Route::get('/zabbix/multi-latest', [MultiLatestValueController::class, 'index']);
    });

    // Hardware API routes — ability:hardware:read (Issue #690)
    Route::prefix('hardware')->middleware('ability:hardware:read')->group(function () {
        Route::get('/', [HardwareController::class, 'index']);
        Route::get('/stats', [HardwareController::class, 'stats']);
        Route::get('/{hardware}', [HardwareController::class, 'show']);

        Route::middleware(['abilities:hardware:write', 'role_or_permission:manage_hardware'])->group(function () {
            Route::post('/', [HardwareController::class, 'store']);
            Route::match(['put', 'patch'], '/{hardware}', [HardwareController::class, 'update']);
            Route::delete('/{hardware}', [HardwareController::class, 'destroy']);
            Route::post('/bulk-mark', [HardwareController::class, 'bulkMark']);
            Route::post('/bulk-delete', [HardwareController::class, 'bulkDelete']);
        });

        // Hardware Audit Trail
        Route::get('/{hardware}/audits', [HardwareAuditController::class, 'index']);
        Route::get('/{hardware}/audits/export', [HardwareAuditController::class, 'export']);
        Route::get('/{hardware}/audits/{audit}', [HardwareAuditController::class, 'show']);
        Route::post('/{hardware}/audits/{audit}/rollback', [HardwareAuditController::class, 'rollback'])
            ->middleware('permission:manage_hardware');
        Route::post('/audits/{audit}/restore-record', [HardwareAuditController::class, 'restoreRecord'])
            ->middleware('permission:manage_hardware');
    });

    // Ticket API routes — ability:tickets:read (Issue #690)
    Route::middleware('ability:tickets:read')->group(function () {
        Route::get('/tickets', [TicketController::class, 'index'])
            ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');

        Route::middleware('abilities:tickets:write')->group(function () {
            Route::post('/tickets', [TicketController::class, 'store'])
                ->middleware('permission:create_ticket');
            Route::put('/tickets/{ticket}', [TicketController::class, 'update'])
                ->middleware('permission:manage_unit_tickets');
            Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])
                ->middleware('permission:manage_unit_tickets');
            Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
                ->middleware('permission:manage_unit_tickets');
            Route::post('/tickets/{ticket}/accept', [TicketController::class, 'accept'])
                ->middleware('permission:create_ticket');
            Route::post('/tickets/{ticket}/complete', [TicketController::class, 'complete'])
                ->middleware('permission:manage_unit_tickets');
        });
    });

    // Ticket Comments — ability:tickets:read (Issue #690)
    Route::middleware('ability:tickets:read')->group(function () {
        Route::get('/tickets/{ticket}/comments', [TicketCommentController::class, 'index'])
            ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
        Route::get('/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'show'])
            ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
        Route::get('/tickets/{ticket}/comments/{comment}/reactions', [TicketCommentController::class, 'reactions'])
            ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');

        Route::middleware('abilities:tickets:write')->group(function () {
            Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
                ->middleware('permission:create_ticket');
            Route::match(['put', 'patch'], '/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'update'])
                ->middleware('permission:manage_unit_tickets');
            Route::delete('/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'destroy'])
                ->middleware('permission:manage_unit_tickets');
            Route::post('/tickets/{ticket}/comments/{comment}/react', [TicketCommentController::class, 'react'])
                ->middleware('permission:create_ticket');
            Route::delete('/tickets/{ticket}/comments/{comment}/react', [TicketCommentController::class, 'unreact'])
                ->middleware('permission:create_ticket');
        });
    });

    // Report API routes — ability:reports:read (Issue #690)
    Route::middleware('ability:reports:read')->group(function () {
        Route::get('/reports/units', [ReportController::class, 'units']);
        Route::get('/reports/todos', [ReportController::class, 'todos']);
        Route::get('/reports/tickets', [ReportController::class, 'tickets']);
    });

    // Person API routes — ability:persons:read (Issue #690)
    Route::prefix('persons')->middleware('ability:persons:read')->group(function () {
        Route::get('/', [PersonController::class, 'index']);
        Route::get('/{person}', [PersonController::class, 'show']);

        Route::middleware(['abilities:persons:write', 'role_or_permission:manage_personnel'])->group(function () {
            Route::post('/', [PersonController::class, 'store']);
            Route::put('/{person}', [PersonController::class, 'update']);
            Route::delete('/{person}', [PersonController::class, 'destroy']);
        });
    });

    // Todo API routes — ability:todos:read|todos:write (Issue #690)
    Route::middleware(['ability:todos:read,todos:write', 'role_or_permission:calendar'])->group(function () {
        Route::get('/todos', [TodoController::class, 'index']);
        Route::get('/todos/{todo}', [TodoController::class, 'show']);
        Route::post('/todos', [TodoController::class, 'store']);
        Route::put('/todos/{todo}', [TodoController::class, 'update']);
        Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);
        Route::post('/todos/{todo}/toggle-complete', [TodoController::class, 'toggleComplete']);
    });

    // HR API routes (Issue #223, #444) — ability:hr:read (Issue #690)
    Route::prefix('hr')->middleware(['ability:hr:read', 'role_or_permission:view_hr_dashboard'])->group(function () {
        // Org chart
        Route::get('/org-chart', [OrgChartController::class, 'orgChart']);
        Route::get('/org-chart/expandable', [OrgChartController::class, 'orgChartExpandable']);
        Route::get('/org-chart/subtree/{unitId}', [OrgChartController::class, 'loadSubtree']);

        // Stats
        Route::get('/stats', [HrStatsController::class, 'stats']);
        Route::get('/vacancies', [HrStatsController::class, 'vacancies']);
        Route::get('/personnel', [HrStatsController::class, 'personnel']);
        Route::get('/personnel/{n_code}', [HrStatsController::class, 'personDetail']);

        // Analytics
        Route::get('/analytics/headcount-trend', [HrAnalyticsController::class, 'headcountTrend']);
        Route::get('/analytics/vacancy-trend', [HrAnalyticsController::class, 'vacancyTrend']);
        Route::get('/analytics/staffing-ratio', [HrAnalyticsController::class, 'staffingRatio']);
    });

    // Notification API routes — ability:notifications:read (Issue #690)
    Route::middleware('ability:notifications:read')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    });

    // GIS / Map API routes — ability:gis:read (Issue #690)
    Route::prefix('gis')->middleware(['ability:gis:read', 'role_or_permission:map'])->group(function () {
        Route::get('/units', [GisController::class, 'units'])->name('api.gis.units');
        Route::get('/hardware', [GisController::class, 'hardware'])->name('api.gis.hardware');
        Route::get('/tickets', [GisController::class, 'tickets'])->name('api.gis.tickets');
        Route::get('/stats', [GisController::class, 'stats'])->name('api.gis.stats');
        Route::get('/clusters', [GisController::class, 'clusters'])->name('api.gis.clusters');
    });
});
