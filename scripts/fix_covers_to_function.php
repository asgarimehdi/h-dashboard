#!/usr/bin/env php
<?php
/**
 * Convert @covers annotations to Pest covers() function calls.
 *
 * Usage: php scripts/fix_covers_to_function.php [--dry-run]
 */

$repoRoot = dirname(__DIR__);
$testsDir = $repoRoot . '/tests';

$dryRun = in_array('--dry-run', $argv);

// Mapping: test filename → covers() argument (FQCN with ::class)
$coversMap = [
    'AccessServiceTest.php' => '\\App\\Services\\AccessService',
    'ActivityLogModelTest.php' => '\\App\\Models\\ActivityLog',
    'ActivityLogPageLivewireTest.php' => '\\App\\Models\\ActivityLog',
    'ActivityLogPageTest.php' => '\\App\\Models\\ActivityLog',
    'ActivityLogServiceTest.php' => '\\App\\Services\\ActivityLogService',
    'ApiLoginTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'ArchiveOldRecordsCommandTest.php' => '\\App\\Console\\Commands\\ArchiveOldRecords',
    'LoginLivewireTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'AuthTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'BoundaryModelTest.php' => '\\App\\Models\\Boundary',
    'CacheInvalidationTest.php' => '\\App\\Services\\CacheInvalidationService',
    'ChangePasswordTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'DashboardPageTest.php' => '\\App\\Models\\Ticket',
    'DashboardTodoNotificationTest.php' => '\\App\\Models\\Todo',
    'DeleteAlreadyDeletedTodoTest.php' => '\\App\\Http\\Controllers\\Api\\TodoController',
    'GenerateDailyReportsCommandTest.php' => '\\App\\Console\\Commands\\GenerateDailyReports',
    'GenerateDueMaintenanceCommandTest.php' => '\\App\\Console\\Commands\\GenerateDueMaintenance',
    'GenerateRecurringTodosCommandTest.php' => '\\App\\Console\\Commands\\GenerateRecurringTodos',
    'GisApiTest.php' => '\\App\\Http\\Controllers\\Api\\GisController',
    'HardwareApiTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'HardwareAuditControllerTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareAuditController',
    'HardwareAuditDetailTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareAuditController',
    'HardwareAuditLivewireTest.php' => '\\App\\Models\\HardwareAudit',
    'HardwareAuditMigrationTest.php' => '\\App\\Models\\HardwareAudit',
    'HardwareAuditModelTest.php' => '\\App\\Models\\HardwareAudit',
    'HardwareAuditObserverTest.php' => '\\App\\Models\\HardwareAudit',
    'HardwareAuditTest.php' => '\\App\\Models\\HardwareAudit',
    'HardwareBulkOperationsTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'HardwareColumnVisibilityTest.php' => '\\App\\Models\\Hardware',
    'HardwareDeletedRestoreTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'HardwareExportTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareExportController',
    'HardwareImportEdgeCasesTest.php' => '\\App\\Imports\\HardwareImport',
    'HardwareImportTest.php' => '\\App\\Imports\\HardwareImport',
    'HardwareModelTest.php' => '\\App\\Models\\Hardware',
    'HardwareScopeTest.php' => '\\App\\Traits\\HasOrganizationalScope',
    'HasOrganizationalScopeTest.php' => '\\App\\Traits\\HasOrganizationalScope',
    'HelpSystemTest.php' => '\\App\\View\\Components\\AppBrand',
    'HrApiTest.php' => '\\App\\Http\\Controllers\\Api\\HrController',
    'HrLivewireTest.php' => '\\App\\Http\\Controllers\\Api\\HrController',
    'HrOrgChartCoverageTest.php' => '\\App\\Http\\Controllers\\Api\\HrController',
    'ImportLivewireComponentTest.php' => '\\App\\Imports\\HardwareImport',
    'ImportsLivewireTest.php' => '\\App\\Imports\\HardwareImport',
    'ItMonitoringTest.php' => '\\App\\Services\\ZabbixService',
    'ItPagesTest.php' => '\\App\\Services\\ZabbixService',
    'PersonLivewireTest.php' => '\\App\\Models\\Person',
    'KargoziniTest.php' => '\\App\\Models\\Person',
    'LastUserActivityMiddlewareTest.php' => '\\App\\Http\\Middleware\\LastUserActivity',
    'LogoutTest.php' => '\\App\\Http\\Controllers\\Api\\HardwareController',
    'LookupModelsTest.php' => '\\App\\Models\\Person',
    'LookupSimpleModelsTest.php' => '\\App\\Models\\Person',
    'MapDashboardTest.php' => '\\App\\Models\\Unit',
    'MapsPagesTest.php' => '\\App\\Models\\Unit',
    'MapsVoltTest.php' => '\\App\\Models\\Unit',
    'MultiLatestValueApiTest.php' => '\\App\\Http\\Controllers\\Api\\MultiLatestValueController',
    'MultiLatestValueControllerTest.php' => '\\App\\Http\\Controllers\\Api\\MultiLatestValueController',
    'NormalizePersianTextCommandTest.php' => '\\App\\Console\\Commands\\NormalizePersianText',
    'NotificationModelTest.php' => '\\App\\Models\\Notification',
    'NotificationServiceTest.php' => '\\App\\Services\\NotificationService',
    'OtherModelsTest.php' => '\\App\\Models\\Ticket',
    'PagesRenderTest.php' => '\\App\\Models\\Ticket',
    'PermissionsPageTest.php' => '\\App\\Models\\User',
    'PersonApiTest.php' => '\\App\\Http\\Controllers\\Api\\PersonController',
    'PersonImportEdgeCasesTest.php' => '\\App\\Imports\\PersonImport',
    'PersonImportTest.php' => '\\App\\Imports\\PersonImport',
    'PersonModelTest.php' => '\\App\\Models\\Person',
    'ProfileTest.php' => '\\App\\Models\\User',
    'PruneStaleCacheCommandTest.php' => '\\App\\Console\\Commands\\PruneStaleCache',
    'ReportApiTest.php' => '\\App\\Http\\Controllers\\Api\\ReportController',
    'ReportsApiTest.php' => '\\App\\Http\\Controllers\\Api\\ReportController',
    'ReportsPagesTest.php' => '\\App\\Models\\Ticket',
    'RolesPageTest.php' => '\\App\\Models\\User',
    'SafeRoleOrPermissionMiddlewareTest.php' => '\\App\\Http\\Middleware\\SafeRoleOrPermission',
    'ScheduledJobInfrastructureTest.php' => '\\App\\Console\\Commands\\PruneStaleCache',
    'SearchTest.php' => '\\App\\Models\\Hardware',
    'SelectContextTest.php' => '\\App\\Models\\Unit',
    'SettingsProfileTest.php' => '\\App\\Models\\User',
    'SettingsTest.php' => '\\App\\Models\\User',
    'SyncZabbixCommandTest.php' => '\\App\\Console\\Commands\\SyncZabbix',
    'TicketApiTest.php' => '\\App\\Http\\Controllers\\Api\\TicketController',
    'TicketCommentApiComprehensiveTest.php' => '\\App\\Http\\Controllers\\Api\\TicketCommentController',
    'TicketCommentModelTest.php' => '\\App\\Models\\TicketComment',
    'TicketCommentPolicyTest.php' => '\\App\\Models\\TicketComment',
    'TicketCommentTest.php' => '\\App\\Models\\TicketComment',
    'TicketCommentsEdgeCasesTest.php' => '\\App\\Http\\Controllers\\Api\\TicketCommentController',
    'TicketCommentsLivewireTest.php' => '\\App\\Models\\TicketComment',
    'TicketCommentsRefreshTest.php' => '\\App\\Models\\TicketComment',
    'TicketControllerEdgeCasesTest.php' => '\\App\\Http\\Controllers\\Api\\TicketController',
    'TicketModelTest.php' => '\\App\\Models\\Ticket',
    'TicketWorkflowTest.php' => '\\App\\Models\\Ticket',
    'TicketsPagesTest.php' => '\\App\\Models\\Ticket',
    'TodoApiTest.php' => '\\App\\Http\\Controllers\\Api\\TodoController',
    'TodoModelTest.php' => '\\App\\Models\\Todo',
    'ToolsLivewireTest.php' => '\\App\\Models\\Ticket',
    'ToolsPageTest.php' => '\\App\\Services\\ZabbixService',
    'TrafficApiTest.php' => '\\App\\Http\\Controllers\\Api\\TrafficController',
    'UnitApiTest.php' => '\\App\\Http\\Controllers\\Api\\UnitController',
    'UnitModelTest.php' => '\\App\\Models\\Unit',
    'UnitTicketCapabilityTest.php' => '\\App\\Models\\Ticket',
    'UnitsManagementTest.php' => '\\App\\Models\\Unit',
    'UserModelTest.php' => '\\App\\Models\\User',
    'UsersManagementTest.php' => '\\App\\Models\\User',
    'UsersPageTest.php' => '\\App\\Models\\User',
    'ValidateUnitContextTest.php' => '\\App\\Http\\Middleware\\ValidateUnitContext',
    'AppBrandTest.php' => '\\App\\View\\Components\\AppBrand',
    'CacheInvalidationServiceTest.php' => '\\App\\Services\\CacheInvalidationService',
    'PersianNormalizerExtraTest.php' => '\\App\\Traits\\PersianNormalizer',
    'ZabbixServiceTest.php' => '\\App\\Services\\ZabbixService',
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

function convertFile(string $filePath, string $fqcn, bool $dryRun): bool {
    $content = file_get_contents($filePath);
    $original = $content;

    // Step 1: Remove existing @covers docblock annotations
    $content = preg_replace(
        '/\/\*\*\s*@covers\s+\\\\?[A-Za-z\\\\]+\s*\*\/\s*\n/',
        '',
        $content
    );

    // Step 2: Add covers() function call
    $coversLine = "covers({$fqcn}::class);\n";

    // Check if already has covers() call
    if (preg_match('/^covers\(/m', $content)) {
        if ($content !== $original) {
            if (!$dryRun) file_put_contents($filePath, $content);
            return true; // removed @covers but already has covers()
        }
        return false;
    }

    // Find insertion point: after <?php, namespace, use statements, comments
    $lines = explode("\n", $content);
    $insertPos = 0;

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $trimmed = ltrim($line);

        // Skip <?php
        if ($trimmed === '<?php') {
            $insertPos = $i + 1;
            continue;
        }

        // Skip empty lines at the top
        if ($trimmed === '' && $i < 5) {
            $insertPos = $i + 1;
            continue;
        }

        // Skip namespace, use, //, /*, *, @
        if (preg_match('/^(namespace |use |\/\/|\/\*|\*|@)/', $trimmed) ||
            $trimmed === '') {
            $insertPos = $i + 1;
            continue;
        }

        // Found something else (class, it(), test(), etc.) — insert before this
        break;
    }

    // Insert covers() call
    array_splice($lines, $insertPos, 0, [$coversLine]);
    $newContent = implode("\n", $lines);

    // Clean up any double blank lines created
    $newContent = preg_replace('/\n{3,}/', "\n\n", $newContent);

    if ($newContent !== $original) {
        if (!$dryRun) file_put_contents($filePath, $newContent);
        return true;
    }

    return false;
}

// Main
$files = findTestFiles($testsDir);
$converted = 0;
$skipped = 0;
$errors = [];

foreach ($files as $file) {
    $filename = basename($file);

    if (!isset($coversMap[$filename])) {
        $skipped++;
        continue;
    }

    $fqcn = $coversMap[$filename];

    if (convertFile($file, $fqcn, $dryRun)) {
        $converted++;
        echo "  ✓ $filename → covers($fqcn::class)\n";
    } else {
        $skipped++;
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Converted: $converted files\n";
echo "Skipped (no mapping or already correct): $skipped files\n";

if ($dryRun) {
    echo "\n[DRY RUN] No files were modified.\n";
}
