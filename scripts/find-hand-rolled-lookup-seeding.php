#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Issue #1044 — ratchet against hand-rolled lookup seeding in tests.
 *
 * `tahsils` / `estekhdams` / `semats` / `radifs` back the `t_id` / `e_id` /
 * `s_id` / `r_id` foreign keys almost every Person row carries. Tests used to
 * seed a deterministic `id = 1` row by hand:
 *
 *     DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
 *
 * Postgres sequences are NOT transactional, so `RefreshDatabase`'s rollback
 * keeps the sequence advancing; the next factory insert then lands on id=2+
 * and every hardcoded `t_id => 1` breaks. `InteractsWithTestSetup::seedLookupTables()`
 * is the one place that seeds id=1 AND resyncs the sequence, so tests must call
 * it instead of re-implementing the insert with `setval`. This is a hard rule
 * in AGENTS.md.
 *
 * This guard counts the files that still hand-roll it and fails once the count
 * rises above the agreed ceiling. 52 were migrated to the trait; the ceiling is
 * therefore 0 and is expected to stay there. A file that legitimately needs an
 * *extra* lookup row (a second `semats` value, say) may keep an explicit insert
 * as long as it also consumes the trait — matching the same rule the migration
 * used to draw its list.
 *
 * Usage:  php scripts/find-hand-rolled-lookup-seeding.php
 * Exit:   0 = count is within the ceiling, 1 = it is over, 2 = the scan could not run.
 */
$root = dirname(__DIR__);

// Number of remaining hand-rolled files tolerated. The migration drove it to 0.
$ceiling = 0;

$lookupTables = ['tahsils', 'estekhdams', 'semats', 'radifs'];
$manualInsertPattern = "/DB::table\\('(?:".implode('|', $lookupTables).")'\\)->insert/";

$traitFile = $root.'/tests/Support/Concerns/InteractsWithTestSetup.php';

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS),
    );
} catch (Throwable $e) {
    fwrite(STDERR, "find-hand-rolled-lookup-seeding: cannot scan tests/: {$e->getMessage()}\n");

    exit(2);
}

$offenders = [];

foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();

    // The trait itself is the one place the raw insert is allowed.
    if ($path === $traitFile) {
        continue;
    }

    $source = file_get_contents($path);

    if ($source === false || ! preg_match($manualInsertPattern, $source)) {
        continue;
    }

    // Consuming the trait (however the file references it) is the contract.
    if (str_contains($source, 'InteractsWithTestSetup') || str_contains($source, 'seedLookupTables')) {
        continue;
    }

    $offenders[] = str_replace($root.'/', '', $path);
}

sort($offenders);

$count = count($offenders);

if ($count > $ceiling) {
    echo "Hand-rolled lookup seeding found in {$count} file(s) (ceiling {$ceiling}):\n";

    foreach ($offenders as $offender) {
        echo "  - {$offender}\n";
    }

    echo "\nUse InteractsWithTestSetup::seedLookupTables() instead of raw\n";
    echo "DB::table('tahsils'|'estekhdams'|'semats'|'radifs')->insert(...) (issue #1044).\n";

    exit(1);
}

echo "No hand-rolled lookup seeding (0 of {$ceiling} tolerated).\n";

exit(0);
