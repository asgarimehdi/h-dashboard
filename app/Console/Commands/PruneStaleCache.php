<?php

namespace App\Console\Commands;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Console\Command;

class PruneStaleCache extends Command
{
    protected $signature = 'cache:prune-stale {--dry-run : Show what would be pruned without actually removing}';

    protected $description = 'Prune stale cache entries from expired versioned keys';

    public function handle(CacheInvalidationServiceInterface $cache): int
    {
        $this->info('Pruning stale cache entries...');

        $namespaces = ['hardware_stats', 'gis', 'maps', 'dashboard', 'hr_stats', 'report_todos', 'report_tickets', 'report_units', 'unit_hierarchy', 'calendar'];
        $pruned = 0;

        foreach ($namespaces as $namespace) {
            $currentVersion = $cache->getVersion($namespace);
            $threshold = $currentVersion - 10; // Keep last 10 versions

            // Redis-specific pruning using tags would be ideal, but we
            // preserve backward compatibility by relying on TTL expiry.
            // This command is a hook point for future tag-based cleanup.
            $this->line("  [{$namespace}] current version: {$currentVersion}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No entries were removed.');
        } else {
            $this->info('Cache prune check complete. Stale keys will expire via TTL.');
        }

        return 0;
    }
}
