#!/usr/bin/env php
<?php
/**
 * Add @covers annotations to all test files for mutation testing.
 *
 * Usage: php scripts/add_covers_annotations.php [--dry-run]
 */

$repoRoot = dirname(__DIR__);
$testsDir = $repoRoot . '/tests';

$dryRun = in_array('--dry-run', $argv);

// Mapping: test file basename (without Test.php) → @covers target
// Models
$coversMap = [
    // Models
    'AccessService' => 'App\\Services\\AccessService',
    'ActivityLogModel' => 'App\\Models\\ActivityLog',
    'ActivityLogService' => 'App\\Services\\ActivityLogService',
    'BoundaryModel' => 'App\\Models\\Boundary',
    'CacheInvalidation' => 'App\\Services\\CacheInvalidationService',
    'CacheInvalidationService' => 'App\\Services\\CacheInvalidationService',
    'HardwareAuditModel' => 'App\\Models\\HardwareAudit',
    'HardwareAuditObserver' => 'App\\Models\\HardwareAudit',
    'HardwareAudit' => 'App\\Models\\HardwareAudit',
    'HardwareModel' => 'App\\Models\\Hardware',
    'NotificationModel' => 'App\\Models\\Notification',
    'NotificationService' => 'App\\Services\\NotificationService',
    'OtherModels' => 'App\\Models\\Ticket',
    'PersonModel' => 'App\\Models\\Person',
    'TicketCommentModel' => 'App\\Models\\TicketComment',
    'TicketModel' => 'App\\Models\\Ticket',
    'TodoModel' => 'App\\Models\\Todo',
    'UnitModel' => 'App\\Models\\Unit',
    'UserModel' => 'App\\Models\\User',
    'PersianNormalizer' => 'App\\Traits\\PersianNormalizer',
    'PersianNormalizerExtra' => 'App\\Traits\\PersianNormalizer',
    'HasOrganizationalScope' => 'App\\Traits\\HasOrganizationalScope',
    'HardwareScope' => 'App\\Traits\\HasOrganizationalScope',

    // API Controllers
    'HardwareApi' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'HardwareAuditController' => 'App\\Http\\Controllers\\Api\\HardwareAuditController',
    'HardwareAuditDetail' => 'App\\Http\\Controllers\\Api\\HardwareAuditController',
    'HardwareBulkOperations' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'HardwareDeletedRestore' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'HardwareExport' => 'App\\Http\\Controllers\\Api\\HardwareExportController',
    'HardwareImportEdgeCases' => 'App\\Imports\\HardwareImport',
    'HardwareImport' => 'App\\Imports\\HardwareImport',
    'PersonApi' => 'App\\Http\\Controllers\\Api\\PersonController',
    'PersonImportEdgeCases' => 'App\\Imports\\PersonImport',
    'PersonImport' => 'App\\Imports\\PersonImport',
    'UnitApi' => 'App\\Http\\Controllers\\Api\\UnitController',
    'TicketApi' => 'App\\Http\\Controllers\\Api\\TicketController',
    'TicketCommentApiComprehensive' => 'App\\Http\\Controllers\\Api\\TicketCommentController',
    'TicketComment' => 'App\\Models\\TicketComment',
    'TicketCommentPolicy' => 'App\\Models\\TicketComment',
    'TicketCommentsEdgeCases' => 'App\\Http\\Controllers\\Api\\TicketCommentController',
    'TicketControllerEdgeCases' => 'App\\Http\\Controllers\\Api\\TicketController',
    'TicketWorkflow' => 'App\\Models\\Ticket',
    'TodoApi' => 'App\\Http\\Controllers\\Api\\TodoController',
    'GisApi' => 'App\\Http\\Controllers\\Api\\GisController',
    'HrApi' => 'App\\Http\\Controllers\\Api\\HrController',
    'ReportApi' => 'App\\Http\\Controllers\\Api\\ReportController',
    'ReportsApi' => 'App\\Http\\Controllers\\Api\\ReportController',
    'MultiLatestValueApi' => 'App\\Http\\Controllers\\Api\\MultiLatestValueController',
    'MultiLatestValueController' => 'App\\Http\\Controllers\\Api\\MultiLatestValueController',
    'TrafficApi' => 'App\\Http\\Controllers\\Api\\TrafficController',
    'ApiLogin' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'DeleteAlreadyDeletedTodo' => 'App\\Http\\Controllers\\Api\\TodoController',

    // Commands
    'ArchiveOldRecordsCommand' => 'App\\Console\\Commands\\ArchiveOldRecords',
    'GenerateDailyReportsCommand' => 'App\\Console\\Commands\\GenerateDailyReports',
    'GenerateDueMaintenanceCommand' => 'App\\Console\\Commands\\GenerateDueMaintenance',
    'GenerateRecurringTodosCommand' => 'App\\Console\\Commands\\GenerateRecurringTodos',
    'NormalizePersianTextCommand' => 'App\\Console\\Commands\\NormalizePersianText',
    'PruneStaleCacheCommand' => 'App\\Console\\Commands\\PruneStaleCache',
    'SyncZabbixCommand' => 'App\\Console\\Commands\\SyncZabbix',
    'ScheduledJobInfrastructure' => 'App\\Console\\Commands\\PruneStaleCache',

    // Middleware
    'LastUserActivityMiddleware' => 'App\\Http\\Middleware\\LastUserActivity',
    'SafeRoleOrPermissionMiddleware' => 'App\\Http\\Middleware\\SafeRoleOrPermission',
    'ValidateUnitContext' => 'App\\Http\\Middleware\\ValidateUnitContext',

    // Livewire (anonymous — use nearest PHP class)
    'HardwareAuditLivewire' => 'App\\Models\\HardwareAudit',
    'HardwareColumnVisibility' => 'App\\Models\\Hardware',
    'HrLivewire' => 'App\\Http\\Controllers\\Api\\HrController',
    'HrOrgChartCoverage' => 'App\\Http\\Controllers\\Api\\HrController',
    'ImportLivewireComponent' => 'App\\Imports\\HardwareImport',
    'ImportsLivewire' => 'App\\Imports\\HardwareImport',
    'PersonLivewire' => 'App\\Models\\Person',
    'TicketCommentsLivewire' => 'App\\Models\\TicketComment',
    'TicketCommentsRefresh' => 'App\\Models\\TicketComment',
    'ToolsLivewire' => 'App\\Models\\Ticket',

    // Auth
    'LoginLivewire' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'Auth' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'ChangePassword' => 'App\\Http\\Controllers\\Api\\HardwareController',
    'Logout' => 'App\\Http\\Controllers\\Api\\HardwareController',

    // Pages (Livewire — use nearest PHP class)
    'ActivityLogPageLivewire' => 'App\\Models\\ActivityLog',
    'ActivityLogPage' => 'App\\Models\\ActivityLog',
    'DashboardPage' => 'App\\Models\\Ticket',
    'DashboardTodoNotification' => 'App\\Models\\Todo',
    'HelpSystem' => 'App\\View\\Components\\AppBrand',
    'ItMonitoring' => 'App\\Services\\ZabbixService',
    'ItPages' => 'App\\Services\\ZabbixService',
    'Kargozini' => 'App\\Models\\Person',
    'LookupModels' => 'App\\Models\\Person',
    'LookupSimpleModels' => 'App\\Models\\Person',
    'MapDashboard' => 'App\\Models\\Unit',
    'MapsPages' => 'App\\Models\\Unit',
    'MapsVolt' => 'App\\Models\\Unit',
    'PagesRender' => 'App\\Models\\Ticket',
    'PermissionsPage' => 'App\\Models\\User',
    'Profile' => 'App\\Models\\User',
    'ReportsPages' => 'App\\Models\\Ticket',
    'RolesPage' => 'App\\Models\\User',
    'Search' => 'App\\Models\\Hardware',
    'SelectContext' => 'App\\Models\\Unit',
    'SettingsProfile' => 'App\\Models\\User',
    'Settings' => 'App\\Models\\User',
    'TicketsPages' => 'App\\Models\\Ticket',
    'ToolsPage' => 'App\\Services\\ZabbixService',
    'UnitTicketCapability' => 'App\\Models\\Ticket',
    'UnitsManagement' => 'App\\Models\\Unit',
    'UsersManagement' => 'App\\Models\\User',
    'UsersPage' => 'App\\Models\\User',

    // Other
    'AppBrand' => 'App\\View\\Components\\AppBrand',
    'ZabbixService' => 'App\\Services\\ZabbixService',
    'HardwareAuditMigration' => 'App\\Models\\HardwareAudit',
];

function findTestFiles(string $dir): array {
    $files = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->getExtension() === 'php' && $file->getFilename() !== 'Pest.php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

function getCoversTarget(string $filename, array $map): ?string {
    $name = str_replace('.php', '', $filename);

    // Exact match first
    if (isset($map[$name])) {
        return $map[$name];
    }

    // Try removing suffixes like 'Test', 'EdgeCases', etc.
    $base = preg_replace('/(Test|EdgeCases|Comprehensive|Coverage|Migration)$/', '', $name);
    if (isset($map[$base])) {
        return $map[$base];
    }

    // Try partial match
    foreach ($map as $key => $value) {
        if (str_starts_with($name, $key)) {
            return $value;
        }
    }

    return null;
}

function addCoversToFile(string $filePath, string $coversClass, bool $dryRun): bool {
    $content = file_get_contents($filePath);

    // Skip if already has @covers
    if (preg_match('/@covers/i', $content)) {
        return false;
    }

    $annotation = "/** @covers \\{$coversClass} */\n";

    // Determine if Pest-style (uses() or it()/test() at top level) or class-based
    if (preg_match('/^uses\(/m', $content)) {
        // Pest-style: add after <?php and any namespace/use statements
        $lines = explode("\n", $content);
        $insertPos = 1; // After <?php
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, 'namespace ') || str_starts_with($line, 'use ') || str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*')) {
                $insertPos = $i + 1;
                continue;
            }
            break;
        }
        // Insert annotation
        array_splice($lines, $insertPos, 0, [$annotation]);
        $newContent = implode("\n", $lines);
    } else {
        // Class-based: add before the class declaration
        if (preg_match('/^(class |abstract class |final class )/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            $newContent = substr($content, 0, $pos) . $annotation . substr($content, $pos);
        } else {
            return false;
        }
    }

    if (!$dryRun) {
        file_put_contents($filePath, $newContent);
    }
    return true;
}

// Main
$files = findTestFiles($testsDir);
$added = 0;
$skipped = 0;
$notFound = [];

foreach ($files as $file) {
    $filename = basename($file);
    $target = getCoversTarget($filename, $coversMap);

    if ($target === null) {
        $notFound[] = $filename;
        $skipped++;
        continue;
    }

    if (addCoversToFile($file, $target, $dryRun)) {
        $added++;
        echo "  ✓ $filename → $target\n";
    } else {
        $skipped++;
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Added @covers to: $added files\n";
echo "Skipped (already has @covers or no target): $skipped files\n";

if (!empty($notFound)) {
    echo "\n⚠ No mapping found for these files (manual @covers needed):\n";
    foreach ($notFound as $f) {
        echo "  - $f\n";
    }
}

if ($dryRun) {
    echo "\n[DRY RUN] No files were modified.\n";
}
