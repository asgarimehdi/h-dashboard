<?php

namespace App\Events;

use App\Models\Hardware;
use Illuminate\Foundation\Events\Dispatchable;

class HardwareUpdated
{
    use Dispatchable;

    public function __construct(
        public Hardware $hardware,
        public string $action, // 'created', 'updated', 'deleted', 'bulk_deleted', 'bulk_restored'
    ) {}
}
