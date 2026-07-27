<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\HardwareAgentController;
use App\Http\Controllers\Api\HardwareController;
use App\Http\Controllers\Api\MultiLatestValueController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TodoController;
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

// AI smoke test endpoint
Route::middleware('auth:sanctum')->post('/ai/chat', AiController::class);

// Hardware AI Agent endpoint
Route::middleware('auth:sanctum')->post('/ai/hardware', HardwareAgentController::class);

// Hardware API routes
Route::middleware('auth:sanctum')->prefix('hardware')->group(function () {
    Route::get('/', [HardwareController::class, 'index']);
    Route::post('/', [HardwareController::class, 'store']);
    Route::get('/{hardware}', [HardwareController::class, 'show']);
    Route::put('/{hardware}', [HardwareController::class, 'update']);
    Route::delete('/{hardware}', [HardwareController::class, 'destroy']);
    Route::post('/bulk-mark', [HardwareController::class, 'bulkMark']);
    Route::post('/bulk-delete', [HardwareController::class, 'bulkDelete']);
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

// Todo API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::get('/todos/{todo}', [TodoController::class, 'show']);
    Route::put('/todos/{todo}', [TodoController::class, 'update']);
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);
    Route::post('/todos/{todo}/toggle-complete', [TodoController::class, 'toggleComplete']);
});
