<?php

use App\Http\Controllers\Api\LocationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Http\Controllers\Api\TrafficController;

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
});

// Authenticated routes
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->get('/unit', \App\Http\Controllers\Api\UnitController::class);

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return $request->user();
});
Route::middleware('auth:sanctum')->post('/location', [LocationController::class, 'store']);
Route::middleware('auth:sanctum')->get('/zabbix/traffic', [TrafficController::class, 'index']);
