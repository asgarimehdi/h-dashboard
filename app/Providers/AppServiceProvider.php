<?php

namespace App\Providers;

use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
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
        Todo::created(function () { Cache::increment('report_todos_version'); Cache::increment('dashboard_version'); });
        Todo::updated(function () { Cache::increment('report_todos_version'); Cache::increment('dashboard_version'); });
        Todo::deleted(function () { Cache::increment('report_todos_version'); Cache::increment('dashboard_version'); });

        Ticket::created(function () { Cache::increment('report_tickets_version'); Cache::increment('gis_version'); Cache::increment('calendar_version'); Cache::increment('dashboard_version'); });
        Ticket::updated(function () { Cache::increment('report_tickets_version'); Cache::increment('gis_version'); Cache::increment('calendar_version'); Cache::increment('dashboard_version'); });
        Ticket::deleted(function () { Cache::increment('report_tickets_version'); Cache::increment('gis_version'); Cache::increment('calendar_version'); Cache::increment('dashboard_version'); });

        // Invalidate units report + hierarchy + GIS + maps + dashboard + HR caches on Unit changes (Issues #340, #372, #391, #395)
        Unit::created(function () { Cache::increment('report_units_version'); Cache::increment('unit_hierarchy_version'); Cache::increment('gis_version'); Cache::increment('maps_version'); Cache::increment('dashboard_version'); Cache::increment('hr_stats_version'); });
        Unit::updated(function () { Cache::increment('report_units_version'); Cache::increment('unit_hierarchy_version'); Cache::increment('gis_version'); Cache::increment('maps_version'); Cache::increment('dashboard_version'); Cache::increment('hr_stats_version'); });
        Unit::deleted(function () { Cache::increment('report_units_version'); Cache::increment('unit_hierarchy_version'); Cache::increment('gis_version'); Cache::increment('maps_version'); Cache::increment('dashboard_version'); Cache::increment('hr_stats_version'); });
    }
}