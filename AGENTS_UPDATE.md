# Update for AGENTS.md: Issue #463 Fix

## Recent Issues Resolved

- **#463** — Prevent Duplicate Hardware Audit Entries on Bulk Operations
  - **Problem**: Bulk operations (e.g., `bulk-mark`, `bulk-delete`) on hardware records could create **duplicate audit entries** in the `hardware_audits` table. While the current implementation bypasses Eloquent events, there was a risk of duplicates if Eloquent events were ever used for bulk operations in the future.
  - **Solution**:
    - Added a `Hardware::$suppressAudit` flag to suppress audit logging during bulk operations.
    - Updated the `HardwareAuditObserver` to respect this flag.
    - Set the flag in `bulkMark` and `bulkDelete` methods before performing the operations.
  - **Files**:
    - `app/Models/Hardware.php` (flag addition).
    - `app/Observers/HardwareAuditObserver.php` (flag check).
    - `app/Http/Controllers/Api/HardwareController.php` (flag usage in bulk operations).
  - **Impact**: Eliminated the risk of duplicate audit entries during bulk operations.

- **#462** — ZabbixSync HTTP Timeout + Scheduler Blocking Prevention
  - **Problem**: The `zabbix:sync` command could hang indefinitely due to:
    - No HTTP timeout for Zabbix API calls.
    - No protection against overlapping executions.
    - Blocking the Laravel scheduler while waiting for the sync to complete.
  - **Solution**:
    - **HTTP Timeout**: Added `->timeout(10)` to all Zabbix API calls in `ZabbixService::request()`.
    - **Scheduler Timeout**: Set `->timeout(15)` on the scheduled command in `Kernel.php`.
    - **No Overlap**: Added `->withoutOverlapping()` to prevent concurrent executions.
    - **Background Execution**: Added `->runInBackground()` to avoid scheduler blocking.
    - **Error Handling**: The `SyncZabbix` command now catches exceptions and logs warnings without crashing.
  - **Files**:
    - `app/Services/ZabbixService.php` (HTTP timeout).
    - `app/Console/Kernel.php` (scheduler improvements).
    - `app/Console/Commands/SyncZabbix.php` (error handling).
  - **Impact**: Eliminated scheduler blocking and prevented indefinite hangs in Zabbix API calls.