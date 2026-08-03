<?php

use App\Http\Controllers\Api\GisController;
use App\Http\Controllers\Api\HardwareController;
use App\Http\Controllers\Api\HardwareAuditController;
use App\Http\Controllers\Api\HrController;
use App\Http\Controllers\Api\MultiLatestValueController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketCommentController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\TrafficController;
use App\Http\Controllers\Api\UnitController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

// Login route
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'n_code' => 'required',
        'password' => 'required',
    ]);

    $user = User::where('n_code', $credentials['n_code'])->first();

    if (! $user || ! Hash::check($credentials['password'], $user->password)) {
        return response()->json(['message' => 'Credentials not match'], 401);
    }

    $token = $user->createToken('flutter-app')->plainTextToken;

    return response()->json(['token' => $token]);
})->middleware('throttle:5,1');

// Authenticated routes
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Unit API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/units', [UnitController::class, 'index']);
    Route::post('/units', [UnitController::class, 'store']);
    Route::get('/units/{unit}', [UnitController::class, 'show']);
    Route::put('/units/{unit}', [UnitController::class, 'update']);
    Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->get('/zabbix/traffic', [TrafficController::class, 'index']);
Route::middleware('auth:sanctum')->get('/zabbix/multi-latest', [MultiLatestValueController::class, 'index']);

// Hardware API routes
Route::middleware('auth:sanctum')->prefix('hardware')->group(function () {
    Route::get('/', [HardwareController::class, 'index']);
    Route::post('/', [HardwareController::class, 'store']);
    Route::get('/stats', [HardwareController::class, 'stats']);
    Route::get('/{hardware}', [HardwareController::class, 'show']);
    Route::match(['put', 'patch'], '/{hardware}', [HardwareController::class, 'update']);
    Route::delete('/{hardware}', [HardwareController::class, 'destroy']);
    Route::post('/bulk-mark', [HardwareController::class, 'bulkMark']);
    Route::post('/bulk-delete', [HardwareController::class, 'bulkDelete']);

    // Hardware Audit Trail (Issue #246 — unified with old /history endpoint)
    Route::get('/{hardware}/history', [\App\Http\Controllers\Api\HardwareAuditController::class, 'index']); // backward-compat alias
    Route::get('/{hardware}/audits', [\App\Http\Controllers\Api\HardwareAuditController::class, 'index']);
    Route::get('/{hardware}/audits/export', [\App\Http\Controllers\Api\HardwareAuditController::class, 'export']);
    Route::get('/{hardware}/audits/{audit}', [\App\Http\Controllers\Api\HardwareAuditController::class, 'show']);
    Route::post('/{hardware}/audits/{audit}/rollback', [\App\Http\Controllers\Api\HardwareAuditController::class, 'rollback']);
});

// Ticket API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    Route::put('/tickets/{ticket}', [TicketController::class, 'update']);
    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy']);
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign']);
    Route::post('/tickets/{ticket}/accept', [TicketController::class, 'accept']);
    Route::post('/tickets/{ticket}/complete', [TicketController::class, 'complete']);

    // Ticket Comments
    Route::get('/tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store']);
    Route::get('/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'show']);
    Route::match(['put', 'patch'], '/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'update']);
    Route::delete('/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'destroy']);
    Route::post('/tickets/{ticket}/comments/{comment}/react', [TicketCommentController::class, 'react']);
    Route::delete('/tickets/{ticket}/comments/{comment}/react', [TicketCommentController::class, 'unreact']);
    Route::get('/tickets/{ticket}/comments/{comment}/reactions', [TicketCommentController::class, 'reactions']);
});

// Report API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/reports/units', [ReportController::class, 'units']);
    Route::get('/reports/todos', [ReportController::class, 'todos']);
    Route::get('/reports/tickets', [ReportController::class, 'tickets']);
});

// Person API routes
Route::middleware('auth:sanctum')->prefix('persons')->group(function () {
    Route::get('/', [PersonController::class, 'index']);
    Route::post('/', [PersonController::class, 'store']);
    Route::get('/{person}', [PersonController::class, 'show']);
    Route::put('/{person}', [PersonController::class, 'update']);
    Route::delete('/{person}', [PersonController::class, 'destroy']);
});

// Todo API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::get('/todos/{todo}', [TodoController::class, 'show']);
    Route::put('/todos/{todo}', [TodoController::class, 'update']);
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);
    Route::post('/todos/{todo}/toggle-complete', [TodoController::class, 'toggleComplete']);
});

// HR API routes (Issue #223)
Route::middleware('auth:sanctum')->prefix('hr')->group(function () {
    Route::get('/org-chart', [HrController::class, 'orgChart']);
    Route::get('/stats', [HrController::class, 'stats']);
    Route::get('/vacancies', [HrController::class, 'vacancies']);
    Route::get('/personnel', [HrController::class, 'personnel']);
    Route::get('/personnel/{n_code}', [HrController::class, 'personDetail']);
});

// GIS / Map API routes
Route::middleware('auth:sanctum')->prefix('gis')->group(function () {
    Route::get('/units', [GisController::class, 'units'])->name('api.gis.units');
    Route::get('/hardware', [GisController::class, 'hardware'])->name('api.gis.hardware');
    Route::get('/tickets', [GisController::class, 'tickets'])->name('api.gis.tickets');
    Route::get('/stats', [GisController::class, 'stats'])->name('api.gis.stats');
    Route::get('/clusters', [GisController::class, 'clusters'])->name('api.gis.clusters');
});