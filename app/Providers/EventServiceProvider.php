<?php

namespace App\Providers;

use App\Events\HardwareUpdated;
use App\Listeners\HardwareGisCacheListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<class-string>>
     */
    protected $listen = [
        HardwareUpdated::class => [
            HardwareGisCacheListener::class,
        ],
    ];

    /**
     * Register any other events for your application.
     */
    public function boot(): void
    {
        //
    }
}
