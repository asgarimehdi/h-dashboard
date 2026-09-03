<?php

namespace App\Listeners;

use App\Events\HardwareUpdated;
use App\Services\CacheInvalidationServiceInterface;

class HardwareGisCacheListener
{
    public function __construct(
        protected CacheInvalidationServiceInterface $cache
    ) {}

    public function handle(HardwareUpdated $event): void
    {
        $this->cache->increment('gis');
    }
}
