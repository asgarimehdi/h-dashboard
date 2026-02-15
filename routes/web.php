<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
Route::get('/zabbix/traffic', function (\App\Services\ZabbixService $zabbix) {

    $outgoing = $zabbix->getInterfaceTraffic(73638); // Bits sent
    $incoming = $zabbix->getInterfaceTraffic(73494); // Bits received

    return response()->json([
        'out' => $outgoing,
        'in'  => $incoming,
    ]);
});



// Users will be redirected to this route if not logged in
Volt::route('/login', 'auth.login')->name('login');
Volt::route('/register', 'auth.register');
// Define the logout
Route::get('/logout', function () {
    $userId = Auth::id();

    // ✅ پاک کردن نام کش‌شده در session
    if ($userId) {
        Session::forget("user_{$userId}_display_name");
    }
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
});

// Protected routes here
Route::middleware('auth')->group(function () {
    // Route::get('/', function () {
    //     return view('welcome');
    // });
    // Route::get('/dashboard', function () {
    //     return view('dashboard');
    // });
    Volt::route('/', 'index'); // صفحه انتخاب نقش
    Volt::route('/dashboard', 'dashboard'); // صفحه داشبورد


    Volt::route('/users', 'users.index');
    Volt::route('/users/create', 'users.create');
    Volt::route('/users/{user}/edit', 'users.edit');
    Volt::route('/users/changepassword', 'auth.changepassword');
    Volt::route('/units', 'units.index');
    Volt::route('/units/chart', 'units.chart');
    // ... more

    Volt::route('/kargozini/estekhdams', 'kargozini.estekhdam');
    Volt::route('/kargozini/tahsils', 'kargozini.tahsil');
    Volt::route('/kargozini/semats', 'kargozini.semat');
    Volt::route('/kargozini/radifs', 'kargozini.radif');
    Volt::route('/kargozini/persons', 'kargozini.person');



    Route::middleware('role_or_permission:map')->group(function () {
        Volt::route('/maps/draw', 'maps/draw');
        Volt::route('/maps/route', 'maps/route');
        Volt::route('/maps/route2', 'maps/route2');
        Volt::route('/maps/county', 'maps/county');
        Volt::route('/maps/unit', 'maps/unit');
        Volt::route('/maps/location', 'maps/location'); //->can('map');
        Volt::route('/maps/point', 'maps/point');
        Volt::route('/card', 'glowingcard');
        Volt::route('/z', 'network-traffic-chart');
    });


   Volt::route('/permissions', 'permissions/index')->name('permissions');
   Volt::route('/roles', 'roles/index')->name('roles');

    Route::middleware('role_or_permission:op-cache')->group(function () {
        // Serve OPcache GUI from non-public resources/views/op/index.php
        Route::get('/op', function () {
            $path = resource_path('views/op/index.php');
            if (!is_file($path)) {
                abort(404, 'OPcache GUI not found.');
            }

            include $path;

        })->name('op');
    });
});

