#!/usr/bin/env php
<?php
/**
 * Convert covers() function calls to #[CoversClass] attribute for class-based tests.
 * Keeps covers() for Pest-style tests.
 */
$repoRoot = dirname(__DIR__);
$testsDir = $repoRoot . '/tests';
$dryRun = in_array('--dry-run', $argv);

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

function fixFile(string $filePath, bool $dryRun): bool {
    $content = file_get_contents($filePath);
    $original = $content;

    // Check if file has covers() function call
    if (!preg_match('/^covers\((.+?)::class\);/m', $content, $matches)) {
        return false;
    }

    $fqcn = $matches[1];

    // Determine if class-based or Pest-style
    $hasClass = preg_match('/^\s*(abstract\s+|final\s+)?class\s+\w+/m', $content);
    $hasPestStyle = preg_match('/^\s*(it|test)\s*\(/m', $content);

    if ($hasClass && !$hasPestStyle) {
        // Class-based: convert covers() to #[CoversClass] attribute
        // Remove the covers() line
        $content = preg_replace('/^covers\(.+?::class\);\s*\n/m', '', $content);

        // Add #[CoversClass] attribute before class declaration
        $content = preg_replace(
            '/^(\s*)(abstract\s+|final\s+)?class\s+(\w+)/m',
            "$1#[CoversClass({$fqcn}::class)]\n$1$2class $3",
            $content,
            1
        );
    }
    // For Pest-style: keep covers() as-is

    if ($content !== $original) {
        if (!$dryRun) file_put_contents($filePath, $content);
        return true;
    }
    return false;
}

$files = findTestFiles($testsDir);
$fixed = 0;
$skipped = 0;

foreach ($files as $file) {
    if (fixFile($file, $dryRun)) {
        $fixed++;
        echo "  ✓ " . basename($file) . "\n";
    } else {
        $skipped++;
    }
}

echo "\nFixed: $fixed files (class-based: covers() -> #[CoversClass])\n";
echo "Skipped: $skipped files (Pest-style or no covers())\n";
if ($dryRun) echo "\n[DRY RUN]\n";
