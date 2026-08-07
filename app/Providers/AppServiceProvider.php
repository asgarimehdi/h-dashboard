<?php

namespace App\Providers;

use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Todo;
use App\Observers\HardwareAuditObserver;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register anonymous help components with colon syntax for Blade
        Blade::component('components.help.button', 'help:button');
        Blade::component('components.help.modal', 'help:modal');
        
        // Register help-content components dynamically with colon syntax
        $helpContents = [
            'dashboard',
            'hardware',
            'hardware-import',
            'persons-import',
            'personnel',
            'units',
            'tickets',
            'todos',
            'reports',
            'maps',
            'settings',
            'roles',
            'permissions',
            'users',
            'activity-log',
            'networks',
            'wireless',
            'tools',
            'search',
            'profile',
            'hr-dashboard',
        ];
        
        foreach ($helpContents as $content) {
            Blade::component("components.help.content.{$content}", "help-content:{$content}");
        }
        
        // Register Hardware Audit observer for field-level change tracking
        // (single unified audit source — replaces the old HardwareHistory observer)
        Hardware::observe(HardwareAuditObserver::class);

        // Invalidate report caches on Todo/Ticket changes (Issue #320)
        Todo::created(fn () => Cache::increment('report_todos_version'));
        Todo::updated(fn () => Cache::increment('report_todos_version'));
        Todo::deleted(fn () => Cache::increment('report_todos_version'));

        Ticket::created(fn () => Cache::increment('report_tickets_version'));
        Ticket::updated(fn () => Cache::increment('report_tickets_version'));
        Ticket::deleted(fn () => Cache::increment('report_tickets_version'));
    }
}