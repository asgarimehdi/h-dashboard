<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'auth.login')->name('login');

// Volt::route('/login', 'auth.login')->name('login');
// Volt::route('/register', 'auth.register');
// Define the logout
Route::get('/logout', function () {
    $userId = Auth::id();
    $userName = Auth::user()?->name ?? 'نامشخص';

    // ثبت فعالیت خروج
    if ($userId) {
        \App\Services\ActivityLogService::logout('خروج از سیستم - کاربر: ' . $userName);
        Session::forget("user_{$userId}_display_name");
    }

    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
});

// Protected routes here
Route::middleware('auth')->group(function () {
    Route::livewire('/select-context', 'select-context');

    Route::middleware('unit_context')->group(function () {
        // Route::get('/', function () {
        //     return view('welcome');
        // });
        // Route::get('/dashboard', function () {
        //     return view('dashboard');
        // });
        Route::livewire('/', 'index'); // صفحه انتخاب نقش
        Route::livewire('/dashboard', 'dashboard');

        Route::middleware('role_or_permission:manage_users')->group(function () {
            Route::livewire('/users', 'users.index');
            Route::livewire('/users/create', 'users.create');
            Route::livewire('/users/{user}/edit', 'users.edit');
        });
        Route::livewire('/users/changepassword', 'auth.changepassword');

        Route::middleware('role_or_permission:organization')->group(function () {
            Route::livewire('/units', 'units.index');
            Route::livewire('/units/chart', 'units.chart');
            Route::livewire('/units/{id}/map', 'units.map');
        });

        // ... more

        Route::middleware('role_or_permission:kargozini')->group(function () {
            Route::livewire('/kargozini/estekhdams', 'kargozini.estekhdam');
            Route::livewire('/kargozini/tahsils', 'kargozini.tahsil');
            Route::livewire('/kargozini/semats', 'kargozini.semat');
            Route::livewire('/kargozini/radifs', 'kargozini.radif');
            Route::livewire('/kargozini/persons', 'kargozini.person');
        });

        Route::middleware('role_or_permission:map')->group(function () {
            Route::livewire('/maps/route', 'maps/route');
            Route::livewire('/maps/route2', 'maps/route2');
            Route::livewire('/maps/county', 'maps/county');
            Route::livewire('/maps/unit', 'maps/unit');
            Route::livewire('/maps/interactive', 'maps/interactive');
            Route::livewire('/maps/point', 'maps/point');

            Route::livewire('/it/wireless', 'it/wireless');
            Route::livewire('/it/networks', 'it/networks');
        });

        Route::middleware('role_or_permission:calendar')->group(function () {
            Route::livewire('/todo', 'todo.todo');
        });

        Route::middleware('role_or_permission:view_all_tickets')->group(function () {
            Route::livewire('/monitoring', 'tickets.monitoring')->name('tickets.monitoring');
        });

        Route::prefix('tickets')->name('tickets.')->group(function () {
            Route::middleware('role_or_permission:create_ticket')->group(function () {
                Route::livewire('/new', 'tickets.create')->name('create');
            });
            Route::middleware('role_or_permission:view_assigned_tickets')->group(function () {
                Route::livewire('/inbox', 'tickets.inbox')->name('inbox');
            });
        });

        Route::middleware('role_or_permission:manage_users')->group(function () {
            Route::livewire('/activity-log', 'activity-log.index')->name('activity-log');
        });

        Route::middleware('role_or_permission:manage_roles')->group(function () {
            Route::livewire('/permissions', 'permissions/index')->name('permissions');
            Route::livewire('/roles', 'roles/index')->name('roles');
        });

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
        // جستجوی سراسری
        Route::livewire('/search', 'search.index')->name('search');

        // گزارش‌ها
        Route::livewire('/reports/tickets', 'reports.advanced')->name('reports.tickets');
        Route::livewire('/reports/units', 'reports.units')->name('reports.units');
        Route::livewire('/reports/todos', 'reports.todos')->name('reports.todos');
        Route::livewire('/reports/persons', 'reports.persons')->name('reports.persons');
        Route::livewire('/reports/map-no-boundary', 'reports.map-no-boundary')->name('reports.map-no-boundary');

        // تنظیمات کاربر
        Route::livewire('/settings', 'settings.index')->name('settings');

        // پروفایل کاربر (نیاز به لاگین)
        Route::livewire('/profile', 'profile.index')->name('profile');
        // ابزارهای مدیریتی
        Route::livewire('/tools', 'tools.tools')->name('tools');
    }); // unit_context
});