<?php

namespace App\Console\Commands;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Console\Command;

class PruneStaleCache extends Command
{
    protected $signature = 'cache:prune-stale';

    protected $description = 'Audit current cache versions per namespace (deletes nothing — stale versioned keys expire via TTL)';

    /**
     * Versioned namespaces maintained by CacheInvalidationService.
     * New namespaces (e.g. `zabbix_devices`, issue #698) must be listed here so
     * `cache:prune-stale` — and its call-count test — stay in sync.
     *
     * @var array<int, string>
     */
    public const NAMESPACES = ['hardware_stats', 'gis', 'maps', 'dashboard', 'hr_stats', 'report_todos', 'report_tickets', 'report_units', 'unit_hierarchy', 'calendar', 'zabbix_devices', 'global_search'];

    public function handle(CacheInvalidationServiceInterface $cache): int
    {
        // Issue #981 (owner decision, option A — honest audit): this command
        // has never deleted anything. Laravel's cache abstraction cannot
        // enumerate keys, so versioned keys orphaned by a version bump stay
        // unreachable and expire via TTL (remember() defaults to 60 minutes).
        // The command is kept as an hourly version audit so operators can
        // see the current version of every registered namespace. The name
        // is historical; the schedule and the NAMESPACES registry are
        // deliberately untouched.
        $this->info('Cache version audit — this command deletes nothing; stale versioned keys expire via TTL.');

        foreach (self::NAMESPACES as $namespace) {
            $currentVersion = $cache->getVersion($namespace);

            $this->line("  [{$namespace}] current version: {$currentVersion}");
        }

        $this->info('Audit complete: '.count(self::NAMESPACES).' namespaces checked, nothing was deleted.');

        return self::SUCCESS;
    }
}
