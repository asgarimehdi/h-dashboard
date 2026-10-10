#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Issue #935 — report test files that PHPUnit/Pest will never run.
 *
 * `tests/Feature/TicketWorkflowTest.php` sat in the suite for a year running
 * ZERO tests: its seven methods were `public function <noun-phrase>`, with no
 * `test` prefix and no `#[Test]`. PHPUnit collects a method only when its name
 * starts with `test` (PHPUnit\Util\Test::isTestMethod) or it carries the
 * attribute, so the file reported success while executing nothing.
 *
 * Nothing in the repo caught it:
 *   - `failOnEmptyTestSuite` fires when a WHOLE run finds no tests. Verified
 *     against this tree: with a dead file beside live ones under `--parallel`,
 *     the run is still green (exit 0). It only guards a targeted run like
 *     `pest tests/Feature/DeadTest.php`.
 *   - `--coverage --min=80` is a ratio, not a count, so removing assertions
 *     from one file does not move it.
 *   - the e2e job has an "Assert tests really executed" step; the PHP suite
 *     had no equivalent.
 *
 * This script is that missing guard for the PHP suites. It tokenises each file
 * and reports the ones declaring no collectable test.
 *
 * Two writing styles must both be recognised, or the sweep is useless:
 *   - PHPUnit classes: `public function test_x()` or `#[Test] public function …`
 *   - Pest closures:  top-level `test('…', fn)` / `it('…', fn)` (138 files)
 *
 * Usage:  php scripts/find-dead-test-files.php
 * Exit:   0 = every file declares a test, 1 = at least one is dead,
 *         2 = the scan itself could not run.
 */
$root = dirname(__DIR__);

/**
 * The suites phpunit.xml declares. Kept in sync with it deliberately: PHPUnit's
 * `<directory>` discovery defaults to the `Test.php` suffix, so anything outside
 * these two directories is never collected and is not this script's business
 * (tests/e2e is Playwright, not PHPUnit).
 */
$suiteDirectories = [
    $root.'/tests/Unit',
    $root.'/tests/Feature',
];

$hasMethod = static function (string $source): bool {
    $tokens = token_get_all($source);
    $count = count($tokens);

    /**
     * `#[Test]` / `#[PHPUnit\Framework\Attributes\Test]` sitting on a method.
     *
     * The attribute NAME is not part of the T_ATTRIBUTE token text on PHP 8 —
     * that token carries only `#[` — so matching on its text reports every
     * attributed test as dead. Read the name tokens inside the brackets.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $all
     */
    $attributeIsTest = static function (array $all, int $index) use (&$attributeIsTest): bool {
        for ($k = $index + 1, $depth = 1; $k < count($all) && $depth > 0; $k++) {
            $token = $all[$k];

            if ($token === '[' || $token === '(') {
                $depth++;

                continue;
            }

            if ($token === ']' || $token === ')') {
                $depth--;

                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] !== T_STRING) {
                continue;
            }

            // `Test` or the tail of `...\Attributes\Test`; not `TestCase`.
            if ($token[1] === 'Test' || str_ends_with($token[1], '\\Test')) {
                return true;
            }
        }

        return false;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            continue;
        }

        // `it('…')` / `test('…')` — the Pest closure style.
        if ($token[0] === T_STRING && ($token[1] === 'it' || $token[1] === 'test')) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }

                if ($tokens[$j] === '(') {
                    return true;
                }

                break;
            }
        }

        if ($token[0] !== T_FUNCTION) {
            continue;
        }

        $name = null;

        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            $name = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            break;
        }

        if ($name === null) {
            continue;
        }

        // PHPUnit's own rule: the name starts with `test`.
        if (str_starts_with($name, 'test')) {
            return true;
        }

        // `#[Test]` (or `#[PHPUnit\Framework\Attributes\Test]`) on the method.
        // Walking backwards from `function`, the attribute appears as
        // `… T_ATTRIBUTE '#[' T_STRING 'Test' ']' T_WHITESPACE T_PUBLIC`. Skip
        // modifiers and whitespace, then step over the WHOLE attribute group —
        // stopping at its closing `]` would walk into the name tokens and miss
        // the opening T_ATTRIBUTE entirely.
        $inAttribute = false;
        $brackets = 0;

        for ($k = $i - 1; $k >= 0 && $k > $i - 32; $k--) {
            $previous = $tokens[$k];

            if (! is_array($previous)) {
                if (! $inAttribute) {
                    if ($previous === ']') {
                        $inAttribute = true;
                        $brackets = 1;

                        continue;
                    }

                    break;
                }

                if ($previous === '[') {
                    $brackets--;

                    if ($brackets === 0) {
                        $inAttribute = false;
                    }
                }

                continue;
            }

            if ($inAttribute) {
                if ($previous[0] === T_ATTRIBUTE) {
                    if ($attributeIsTest($tokens, $k)) {
                        return true;
                    }
                }

                continue;
            }

            if ($previous[0] === T_WHITESPACE) {
                continue;
            }

            if (in_array($previous[0], [T_PUBLIC, T_FINAL, T_STATIC, T_PROTECTED], true)) {
                continue;
            }

            break;
        }
    }

    return false;
};

$missingDirectories = array_filter($suiteDirectories, static fn (string $dir): bool => ! is_dir($dir));

if ($missingDirectories !== []) {
    fwrite(STDERR, 'Cannot scan — missing suite directory: '.implode(', ', $missingDirectories)."\n");

    exit(2);
}

$deadFiles = [];
$scanned = 0;

foreach ($suiteDirectories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if ($source === false) {
            fwrite(STDERR, "Cannot read {$file->getPathname()}\n");

            exit(2);
        }

        $scanned++;

        if (! $hasMethod($source)) {
            $deadFiles[] = str_replace($root.'/', '', $file->getPathname());
        }
    }
}

sort($deadFiles);

echo "Scanned {$scanned} test files in tests/Unit and tests/Feature.\n";

if ($deadFiles === []) {
    echo "OK — every test file declares at least one test PHPUnit will collect.\n";

    exit(0);
}

echo "\n";
echo count($deadFiles)." test file(s) run ZERO tests:\n";

foreach ($deadFiles as $deadFile) {
    echo "  - {$deadFile}\n";
}

echo "\n";
echo "PHPUnit only collects a method named test* or carrying #[Test]. Pest only\n";
echo "collects a top-level test()/it() call. A file with neither is silently\n";
echo "skipped: it reports success while executing nothing (issue #935).\n";

exit(1);
