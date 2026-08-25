<?php

use App\Http\Controllers\Api\GisController;
use App\Http\Controllers\Api\HardwareAuditController;
use App\Http\Controllers\Api\HardwareController;
use App\Http\Controllers\Api\HrController;
use App\Http\Controllers\Api\MultiLatestValueController;
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
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // 'password'
    }
    $userHash = $user ? $user->password : $dummyHash;
    $passwordMatches = Hash::check($credentials['password'], $userHash);

    if (! $user || ! $passwordMatches) {
        return response()->json(['message' => 'Credentials not match'], 401);
    }

    $token = $user->createToken('flutter-app')->plainTextToken;

    return response()->json(['token' => $token]);
})->middleware('throttle:5,1');

// Authenticated routes — global rate limit: 60 req/min
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Unit API routes — write gated (Issue #396)
    Route::get('/units', [UnitController::class, 'index']);
    Route::get('/units/{unit}', [UnitController::class, 'show']);
    Route::middleware('role_or_permission:organization')->group(function () {
        Route::post('/units', [UnitController::class, 'store']);
        Route::put('/units/{unit}', [UnitController::class, 'update']);
        Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
    });

    Route::get('/zabbix/traffic', [TrafficController::class, 'index']);
    Route::get('/zabbix/multi-latest', [MultiLatestValueController::class, 'index']);

    // Hardware API routes — write gated (Issue #396)
    Route::prefix('hardware')->group(function () {
        Route::get('/', [HardwareController::class, 'index']);
        Route::get('/stats', [HardwareController::class, 'stats']);
        Route::get('/{hardware}', [HardwareController::class, 'show']);

        Route::middleware('role_or_permission:manage_hardware')->group(function () {
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

    // Ticket API routes — permission-gated (Issue #323)
    Route::get('/tickets', [TicketController::class, 'index'])
        ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
    Route::post('/tickets', [TicketController::class, 'store'])
        ->middleware('permission:create_ticket');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
        ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
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

    // Ticket Comments — permission-gated (Issue #323)
    Route::get('/tickets/{ticket}/comments', [TicketCommentController::class, 'index'])
        ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
        ->middleware('permission:create_ticket');
    Route::get('/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'show'])
        ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');
    Route::match(['put', 'patch'], '/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'update'])
        ->middleware('permission:manage_unit_tickets');
    Route::delete('/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'destroy'])
        ->middleware('permission:manage_unit_tickets');
    Route::post('/tickets/{ticket}/comments/{comment}/react', [TicketCommentController::class, 'react'])
        ->middleware('permission:create_ticket');
    Route::delete('/tickets/{ticket}/comments/{comment}/react', [TicketCommentController::class, 'unreact'])
        ->middleware('permission:create_ticket');
    Route::get('/tickets/{ticket}/comments/{comment}/reactions', [TicketCommentController::class, 'reactions'])
        ->middleware('role_or_permission:view_assigned_tickets|view_all_tickets');

    // Report API routes
    Route::get('/reports/units', [ReportController::class, 'units']);
    Route::get('/reports/todos', [ReportController::class, 'todos']);
    Route::get('/reports/tickets', [ReportController::class, 'tickets']);

    // Person API routes — write gated (Issue #396)
    Route::prefix('persons')->group(function () {
        Route::get('/', [PersonController::class, 'index']);
        Route::get('/{person}', [PersonController::class, 'show']);

        Route::middleware('role_or_permission:manage_personnel')->group(function () {
            Route::post('/', [PersonController::class, 'store']);
            Route::put('/{person}', [PersonController::class, 'update']);
            Route::delete('/{person}', [PersonController::class, 'destroy']);
        });
    });

    // Todo API routes — gated on calendar permission (Issue #396)
    Route::middleware('role_or_permission:calendar')->group(function () {
        Route::get('/todos', [TodoController::class, 'index']);
        Route::get('/todos/{todo}', [TodoController::class, 'show']);
        Route::post('/todos', [TodoController::class, 'store']);
        Route::put('/todos/{todo}', [TodoController::class, 'update']);
        Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);
        Route::post('/todos/{todo}/toggle-complete', [TodoController::class, 'toggleComplete']);
    });

    // HR API routes (Issue #223, #444) — view gated (Issue #396)
    Route::prefix('hr')->middleware('role_or_permission:view_hr_dashboard')->group(function () {
        Route::get('/org-chart', [HrController::class, 'orgChart']);
        Route::get('/org-chart/expandable', [HrController::class, 'orgChartExpandable']);
        Route::get('/org-chart/subtree/{unitId}', [HrController::class, 'loadSubtree']);
        Route::get('/stats', [HrController::class, 'stats']);
        Route::get('/vacancies', [HrController::class, 'vacancies']);
        Route::get('/personnel', [HrController::class, 'personnel']);
        Route::get('/personnel/{n_code}', [HrController::class, 'personDetail']);
        Route::get('/analytics/headcount-trend', [HrController::class, 'headcountTrend']);
        Route::get('/analytics/vacancy-trend', [HrController::class, 'vacancyTrend']);
        Route::get('/analytics/staffing-ratio', [HrController::class, 'staffingRatio']);
    });

    // GIS / Map API routes — view gated (Issue #396)
    Route::prefix('gis')->middleware('role_or_permission:map')->group(function () {
        Route::get('/units', [GisController::class, 'units'])->name('api.gis.units');
        Route::get('/hardware', [GisController::class, 'hardware'])->name('api.gis.hardware');
        Route::get('/tickets', [GisController::class, 'tickets'])->name('api.gis.tickets');
        Route::get('/stats', [GisController::class, 'stats'])->name('api.gis.stats');
        Route::get('/clusters', [GisController::class, 'clusters'])->name('api.gis.clusters');
    });
});
