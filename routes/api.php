<?php

use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MultiLatestValueController;
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

Route::middleware('auth:sanctum')->get('/unit', UnitController::class);

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return $request->user();
});
Route::middleware('auth:sanctum')->post('/location', [LocationController::class, 'store']);
Route::middleware('auth:sanctum')->get('/zabbix/traffic', [TrafficController::class, 'index']);
Route::middleware('auth:sanctum')->get('/zabbix/multi-latest', [MultiLatestValueController::class, 'index']);

// Todo API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::get('/todos/{todo}', [TodoController::class, 'show']);
    Route::put('/todos/{todo}', [TodoController::class, 'update']);
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);
    Route::post('/todos/{todo}/toggle-complete', [TodoController::class, 'toggleComplete']);
});
