<?php

use App\Http\Controllers\Api\HardwareController;
use App\Http\Controllers\Api\HardwareHistoryController;
use App\Http\Controllers\Api\MultiLatestValueController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\TrafficController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\ZabbixConfigController;
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

// Zabbix Configuration Management (Issue #247)
Route::middleware('auth:sanctum')->prefix('zabbix')->group(function () {
    // Hosts
    Route::get('/hosts', [ZabbixConfigController::class, 'hostsIndex']);
    Route::post('/hosts', [ZabbixConfigController::class, 'hostStore']);
    Route::get('/hosts/{host}', [ZabbixConfigController::class, 'hostShow']);
    Route::put('/hosts/{host}', [ZabbixConfigController::class, 'hostUpdate']);
    Route::delete('/hosts/{host}', [ZabbixConfigController::class, 'hostDestroy']);
    Route::post('/hosts/{host}/sync', [ZabbixConfigController::class, 'hostSync']);
    Route::get('/hosts/{host}/discover', [ZabbixConfigController::class, 'hostDiscover']);

    // Items
    Route::get('/items', [ZabbixConfigController::class, 'itemsIndex']);
    Route::post('/items', [ZabbixConfigController::class, 'itemStore']);
    Route::get('/items/{item}', [ZabbixConfigController::class, 'itemShow']);
    Route::put('/items/{item}', [ZabbixConfigController::class, 'itemUpdate']);
    Route::delete('/items/{item}', [ZabbixConfigController::class, 'itemDestroy']);
    Route::post('/items/bulk-sync', [ZabbixConfigController::class, 'itemsBulkSync']);

    // Pairs
    Route::get('/pairs', [ZabbixConfigController::class, 'pairsIndex']);
    Route::post('/pairs', [ZabbixConfigController::class, 'pairStore']);
    Route::get('/pairs/{pair}', [ZabbixConfigController::class, 'pairShow']);
    Route::put('/pairs/{pair}', [ZabbixConfigController::class, 'pairUpdate']);
    Route::delete('/pairs/{pair}', [ZabbixConfigController::class, 'pairDestroy']);
});

// Hardware API routes
Route::middleware('auth:sanctum')->prefix('hardware')->group(function () {
    Route::get('/', [HardwareController::class, 'index']);
    Route::post('/', [HardwareController::class, 'store']);
    Route::get('/stats', [HardwareController::class, 'stats']);
    Route::get('/{hardware}', [HardwareController::class, 'show']);
    Route::match(['put', 'patch'], '/{hardware}', [HardwareController::class, 'update']);
    Route::delete('/{hardware}', [HardwareController::class, 'destroy']);
    Route::get('/{hardware}/history', [HardwareController::class, 'history']);
    Route::post('/bulk-mark', [HardwareController::class, 'bulkMark']);
    Route::post('/bulk-delete', [HardwareController::class, 'bulkDelete']);
    
    // Hardware History
    Route::get('/{hardware}/history', [HardwareHistoryController::class, 'index']);
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