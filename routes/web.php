<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Users will be redirected to this route if not logged in
Volt::route('/login', 'auth.login')->name('login');
Volt::route('/register', 'auth.register');
// Define the logout
Route::get('/logout', function () {
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


    Volt::route('/roles', 'auth.roles');
    Volt::route('/permissions', 'auth.permissions');
    Volt::route('/accesslevels', 'auth.accesslevels');
    Volt::route('/permissions-accesslevels', 'auth.permissionsaccesslevels');
    Volt::route('/roles-accesslevels', 'auth.rolesaccesslevels');

    Volt::route('/maps/draw', 'maps/draw');
    Volt::route('/maps/route', 'maps/route');
    Volt::route('/maps/route2', 'maps/route2');
    Volt::route('/maps/county', 'maps/county');
    Volt::route('/maps/unit', 'maps/unit');
    Volt::route('/maps/location', 'maps/location');
    Volt::route('/card', 'glowingcard');
});
