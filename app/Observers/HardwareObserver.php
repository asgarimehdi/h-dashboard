<?php

namespace App\Observers;

use App\Models\Hardware;

/**
 * DEPRECATED (Issue #246 merge):
 * The old `HardwareHistory`-based change tracking has been replaced by the
 * unified `HardwareAuditObserver` writing to `hardware_audits`.
 *
 * This observer is intentionally left empty and NO LONGER registered in
 * AppServiceProvider, so hardware changes are recorded exactly once.
 */
class HardwareObserver
{
}
