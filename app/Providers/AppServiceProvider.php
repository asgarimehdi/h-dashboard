<?php

namespace App\Providers;

use App\Models\Hardware;
use App\Observers\HardwareObserver;
use Illuminate\Support\Facades\Blade;
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
        
        // Register Hardware observer for audit trail
        Hardware::observe(HardwareObserver::class);
    }
}