#!/usr/bin/env php
<?php
/**
 * Fix files where covers() was inserted before <?php tag.
 * Move covers() to after <?php and use statements.
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

    // Check if covers() is before <?php
    if (!preg_match('/^covers\((.+?)::class\);/m', $content, $matches)) {
        return false;
    }

    // If covers() is before <?php, we need to restructure
    if (preg_match('/^covers\(.+?::class\);/m', $content) && 
        preg_match('/^<\?php/m', $content)) {
        
        // Extract the covers() line
        preg_match('/^covers\((.+?)::class\);/m', $content, $coversMatch);
        $fqcn = $coversMatch[1];
        $coversLine = "covers({$fqcn}::class);";
        
        // Remove covers() line from wherever it is
        $content = preg_replace('/^covers\(.+?::class\);\s*\n?/m', '', $content);
        
        // Now insert covers() after <?php and use statements
        $lines = explode("\n", $content);
        $insertPos = 0;
        
        for ($i = 0; $i < count($lines); $i++) {
            $trimmed = ltrim($lines[$i]);
            
            // Skip <?php
            if ($trimmed === '<?php') {
                $insertPos = $i + 1;
                continue;
            }
            
            // Skip empty lines right after <?php
            if ($trimmed === '' && $i < 5 && $insertPos > 0) {
                $insertPos = $i + 1;
                continue;
            }
            
            // Skip namespace, use, comments
            if (preg_match('/^(namespace |use |\/\/|\/\*|\*|@)/', $trimmed) || $trimmed === '') {
                $insertPos = $i + 1;
                continue;
            }
            
            break;
        }
        
        // Insert covers() at the right position
        array_splice($lines, $insertPos, 0, [$coversLine, '']);
        $content = implode("\n", $lines);
        
        // Clean up triple+ blank lines
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
echo "\nFixed: $fixed files\n";
if ($dryRun) echo "[DRY RUN]\n";
