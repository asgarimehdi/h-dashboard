#!/usr/bin/env php
<?php
/**
 * Convert #[CoversClass] attributes to covers() function calls for ALL test files.
 * Pest mutation testing only recognizes the covers() function, not the PHP attribute.
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

    // Check if file has #[CoversClass(...)] attribute
    if (preg_match('/#\[CoversClass\((.+?)::class\)\]/', $content, $matches)) {
        $fqcn = $matches[1];
        
        // Remove #[CoversClass] attribute
        $content = preg_replace('/#\[CoversClass\(.+?::class\)\]\s*\n/', '', $content);
        
        // Add covers() function call — insert after use statements, before class
        $lines = explode("\n", $content);
        $insertPos = 0;
        for ($i = 0; $i < count($lines); $i++) {
            $trimmed = ltrim($lines[$i]);
            if (preg_match('/^(namespace |use |\/\/|\/\*|\*|@)/', $trimmed) || $trimmed === '') {
                if ($trimmed !== '' || $i < 5) {
                    $insertPos = $i + 1;
                    continue;
                }
            }
            break;
        }
        array_splice($lines, $insertPos, 0, ["covers({$fqcn}::class);\n"]);
        $content = implode("\n", $lines);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
    }

    if ($content !== $original) {
        if (!$dryRun) file_put_contents($filePath, $content);
        return true;
    }
    return false;
}

$files = findTestFiles($testsDir);
$fixed = 0;
foreach ($files as $file) {
    if (fixFile($file, $dryRun)) {
        $fixed++;
        echo "  ✓ " . basename($file) . "\n";
    }
}
echo "\nConverted: $fixed files (#[CoversClass] -> covers())\n";
if ($dryRun) echo "[DRY RUN]\n";
