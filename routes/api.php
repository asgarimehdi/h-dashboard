<?php

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;
Route::post('/login', function(Request $request) {
    $credentials = $request->validate([
        'n_code'    => 'required',
        'password' => 'required',
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json(['message' => 'Credentials not match'], 401);
    }

    /** @var \App\Models\User $user **/
    $user = $request->user();
    $token = $user->createToken('flutter-app')->plainTextToken;
    return response()->json(['token' => $token]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/unit', \App\Http\Controllers\Api\UnitController::class)->middleware('auth');
