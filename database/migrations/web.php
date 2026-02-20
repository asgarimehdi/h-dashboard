<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// وارد کردن کامپوننت‌های Livewire
use App\Livewire\Provinces\ProvinceIndex;
use App\Livewire\Cities\CityIndex;
use App\Livewire\UnitTypes\UnitTypeIndex;
use App\Livewire\UnitTypes\UnitTypeHierarchyManager;
use App\Livewire\Units\UnitIndex;
use App\Livewire\Units\UnitTree;
use App\Livewire\Users\UserIndex;
use App\Livewire\Tickets\CreateTicket;
use App\Livewire\Tickets\TicketInbox;
use App\Livewire\Roles\RoleManager;

/*
|--------------------------------------------------------------------------
| روت‌های عمومی (Public Routes)
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| روت‌های تحت نظارت سیستم احراز هویت (Authenticated Routes)
|--------------------------------------------------------------------------
*/

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Auth;


Route::middleware(['auth', 'verified'])->group(function () {

    // داشبورد اصلی
    // Route::get('/dashboard', function () {
    //     return view('dashboard');
    // })->name('dashboard');
    Route::get('/dashboard', function () {
        $user = Auth::user();
        $myId = $user->id;
        $myUnitId = $user->unit_id;

        $stats = [
            // ۱. تیکت‌های ورودی جدید واحد (بررسی نشده)
            'pending_inbox' => Ticket::where('unit_id', $myUnitId)
                ->whereIn('status', ['created', 'forwarded'])->count(),

            // ۲. تیکت‌های در حال پیگیری توسط من
            'my_in_progress' => Ticket::where('current_assignee_id', $myId)
                ->where('status', 'accepted')->count(),

            // ۳. تیکت‌های انجام شده توسط من
            'my_completed' => Ticket::where('current_assignee_id', $myId)
                ->where('status', 'completed')->count(),

            // ۴. ارسالی‌های من در انتظار تایید
            'my_outbox_waiting' => Ticket::where('user_id', $myId)
                ->whereIn('status', ['created', 'forwarded'])->count(),

            // ۵. ارسالی‌های من که تایید شده
            'my_outbox_accepted' => Ticket::where('user_id', $myId)
                ->where('status', 'accepted')->count(),

            // ۶. ارسالی‌های من که رد شده
            'my_outbox_rejected' => Ticket::where('user_id', $myId)
                ->where('status', 'rejected')->count(),

            // ۷. ارسالی‌های من که نهایی و تمام شده
            'my_outbox_done' => Ticket::where('user_id', $myId)
                ->where('status', 'completed')->count(),

            // ۸. فیلدی که باعث خطا شده بود (کل کاربران)
            'total_users' => User::count(),

            // ۹. آخرین فعالیت‌ها
            'recent_tickets' => Ticket::where('unit_id', $myUnitId)
                ->orWhere('user_id', $myId)
                ->with(['user', 'unit', 'assignee'])
                ->latest()->take(5)->get(),
        ];

        return view('dashboard', compact('stats'));
    })->middleware(['auth', 'verified'])->name('dashboard');

    // مسیر مانیتورینگ
    Route::get('/monitoring', \App\Livewire\Tickets\AllTicketsMonitoring::class)->name('tickets.monitoring');


    // مدیریت پروفایل کاربر
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'edit')->name('profile.edit');
        Route::patch('/profile', 'update')->name('profile.update');
        Route::delete('/profile', 'destroy')->name('profile.destroy');
    });

    /* --- سیستم تیکتینگ (دسترسی عمومی کاربران لاگین شده) --- */
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/inbox', TicketInbox::class)->name('inbox');
        Route::get('/new', CreateTicket::class)->name('create');
    });

    /* |----------------------------------------------------------------------
    | روت‌های مدیریتی (Admin Only)
    | اینجا رول 'admin' را اعمال کرده‌ایم. 
    | اگر از Spatie استفاده می‌کنید، میان‌افزار 'role:admin' است.
    |----------------------------------------------------------------------
    */
    Route::middleware(['role:superadmin'])->group(function () {

        // مدیریت سطوح دسترسی و نقش‌ها
        Route::get('/role-manager', RoleManager::class)->name('roles.manager');

        // مدیریت کاربران
        Route::get('/users', UserIndex::class)->name('users.index');

        // مدیریت تعاریف پایه (استان و شهر)
        Route::get('/provinces', ProvinceIndex::class)->name('provinces.index');
        Route::get('/cities', CityIndex::class)->name('cities.index');

        // مدیریت ساختار واحدها و سلسله مراتب
        Route::prefix('unit-types')->name('unit-types.')->group(function () {
            Route::get('/', UnitTypeIndex::class)->name('index');
            Route::get('/hierarchy', UnitTypeHierarchyManager::class)->name('hierarchy');
        });

        Route::prefix('units')->name('units.')->group(function () {
            Route::get('/', UnitIndex::class)->name('index');
            Route::get('/tree', UnitTree::class)->name('tree');
        });
    });
});
/*
|--------------------------------------------------------------------------
| روت‌های احراز هویت Breeze (Login, Register, Logout, ...)
|--------------------------------------------------------------------------
*/
require __DIR__ . '/auth.php';
