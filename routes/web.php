<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'auth.login')->name('login');

// Volt::route('/login', 'auth.login')->name('login');
// Volt::route('/register', 'auth.register');
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
    Route::livewire('/', 'index'); // صفحه انتخاب نقش
    Route::livewire('/dashboard', 'dashboard');

    Route::livewire('/users', 'users.index');
    Route::livewire('/users/create', 'users.create');
    Route::livewire('/users/{user}/edit', 'users.edit');
    Route::livewire('/users/changepassword', 'auth.changepassword');
    Route::livewire('/units', 'units.index');
    Route::livewire('/units/chart', 'units.chart');

    // ... more

    Route::livewire('/kargozini/estekhdams', 'kargozini.estekhdam');
    Route::livewire('/kargozini/tahsils', 'kargozini.tahsil');
    Route::livewire('/kargozini/semats', 'kargozini.semat');
    Route::livewire('/kargozini/radifs', 'kargozini.radif');
    Route::livewire('/kargozini/persons', 'kargozini.person');

    Route::middleware('role_or_permission:map')->group(function () {
        Route::livewire('/maps/draw', 'maps/draw');
        Route::livewire('/maps/route', 'maps/route');
        Route::livewire('/maps/route2', 'maps/route2');
        Route::livewire('/maps/county', 'maps/county');
        Route::livewire('/maps/unit', 'maps/unit');
        Route::livewire('/maps/location', 'maps/location'); // ->can('map');
        Route::livewire('/maps/point', 'maps/point');
        // Volt::route('/card', 'glowingcard');

        Route::livewire('/it/wireless', 'it/wireless');
        Route::livewire('/it/networks', 'it/networks');
    });

    Route::middleware('role_or_permission:calendar')->group(function () {
        Route::livewire('/todo', 'todo.todo');
    });

    Route::livewire('/monitoring', 'tickets.monitoring')->name('tickets.monitoring');

    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::livewire('/inbox', 'tickets.inbox')->name('inbox');
        Route::livewire('/new', 'tickets.create')->name('create');
    });

    Route::livewire('/permissions', 'permissions/index')->name('permissions');
    Route::livewire('/roles', 'roles/index')->name('roles');

    Route::middleware('role_or_permission:op-cache')->group(function () {
        // Serve OPcache GUI from non-public resources/views/op/index.php
        Route::get('/op', function () {
            $path = resource_path('views/op/index.php');
            if (! is_file($path)) {
                abort(404, 'OPcache GUI not found.');
            }

            include $path;
        })->name('op');
    });
});
