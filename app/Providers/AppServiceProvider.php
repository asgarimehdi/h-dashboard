<?php

namespace App\Providers;

use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Observers\HardwareAuditObserver;
use App\Services\CacheInvalidationService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CacheInvalidationServiceInterface::class,
            CacheInvalidationService::class
        );
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
        $invalidate = function (array $namespaces) {
            $cache = app(CacheInvalidationServiceInterface::class);
            foreach ($namespaces as $ns) {
                $cache->increment($ns);
            }
        };

        $todoNamespaces = ['report_todos', 'dashboard'];
        Todo::created(fn () => $invalidate($todoNamespaces));
        Todo::updated(fn () => $invalidate($todoNamespaces));
        Todo::deleted(fn () => $invalidate($todoNamespaces));

        $ticketNamespaces = ['report_tickets', 'gis', 'calendar', 'dashboard'];
        Ticket::created(fn () => $invalidate($ticketNamespaces));
        Ticket::updated(fn () => $invalidate($ticketNamespaces));
        Ticket::deleted(fn () => $invalidate($ticketNamespaces));

        // Invalidate units report + hierarchy + GIS + HR caches on Unit changes (Issues #340, #372, #391)
        $unitNamespaces = ['report_units', 'unit_hierarchy', 'gis', 'hr_stats'];
        Unit::created(fn () => $invalidate($unitNamespaces));
        Unit::updated(fn () => $invalidate($unitNamespaces));
        Unit::deleted(fn () => $invalidate($unitNamespaces));
    }
}
