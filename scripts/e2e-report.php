<?php

/**
 * #1469 §5 — turn the Playwright JSON report into visible CI truth.
 *
 * Why this exists: the `e2e` job is `continue-on-error: true` (#867), so its
 * conclusion is always `success` even when tests fail, and the old assert step
 * only proved that *something* ran. A red suite therefore looked green twice
 * over: once from the job's conclusion, once from a pipeline whose exit code
 * was `tee`'s. This step reads Playwright's own machine-readable counts and
 *
 *   - keeps the #865 zero-tests guard (a green run with no tests is a red run),
 *   - writes a table to $GITHUB_STEP_SUMMARY,
 *   - emits one `::error file=…,line=…::…` annotation per failed test,
 *   - exits non-zero when failed > 0, flaky > 0, or did_not_run > 0.
 *
 * `did_not_run` is deliberately NOT `stats.skipped`: a suite with a deliberate
 * `test.skip()` / `test.fixme()` (several exist and are environment-dependent)
 * would otherwise turn this step red on a perfectly good run. A test "did not
 * run" when its serial group swallowed it after an earlier failure — one red
 * test hiding the six behind it, which is exactly the failure this gate exists
 * to surface. The JSON marks both cases `status: "skipped"`, and tells them
 * apart with `expectedStatus` (measured against Playwright 1.65):
 *
 *   deliberate skip → status="skipped", expectedStatus="skipped", has a
 *                     "skip"/"fixme" annotation
 *   serial abort    → status="skipped", expectedStatus="passed",  only a
 *                     "serial" annotation
 *
 * So a spec "did not run" when every one of its tests is `skipped` while none
 * of them was *expected* to be skipped. Intentional skips stay quiet.
 *
 * Usage: php scripts/e2e-report.php [e2e-results.json] [e2e-list.log]
 */
$resultsPath = $argv[1] ?? 'e2e-results.json';
$logPath = $argv[2] ?? 'e2e-list.log';

/** Print a GitHub workflow error and fail the step. */
function e2eReportFail(string $message): never
{
    fwrite(STDERR, '::error::'.$message.PHP_EOL);
    exit(1);
}

/**
 * Report a path relative to the checkout, for the annotation/summary.
 *
 * Playwright's JSON `file` fields are relative to the config's `testDir`
 * (`tests/e2e`, pinned in playwright.config.ts), while GitHub annotations
 * want a checkout-relative path. Absolute paths only need the cwd stripped.
 */
function e2eReportRelative(string $path): string
{
    if (str_starts_with($path, '/')) {
        $root = getcwd();

        return $root !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            ? substr($path, strlen($root) + 1)
            : $path;
    }

    return str_starts_with($path, 'tests/') ? $path : 'tests/e2e/'.$path;
}

// ---- #865: a green job that executed zero tests is a failure, not a pass. ----
//
// The list log proves the suite started (it still prints its `N passed` summary
// and the failure section) but NOT the per-test `✓` lines: when a second
// reporter takes the terminal slot Playwright suppresses them (measured with
// 1.65 and `--reporter=list --reporter=json`). The executed-test count is read
// from Playwright's own JSON below instead of being grepped out of the log.

if (! is_file($logPath)) {
    e2eReportFail($logPath.' is missing — the suite did not run.');
}

$log = (string) file_get_contents($logPath);

if (preg_match('/[0-9]+ passed/', $log) !== 1) {
    e2eReportFail('no "N passed" line in '.$logPath.' — the suite did not run.');
}

// ---- Playwright's own counts ----

if (! is_file($resultsPath)) {
    e2eReportFail($resultsPath.' is missing — the JSON reporter did not write it.');
}

$data = json_decode((string) file_get_contents($resultsPath), true);

if (! is_array($data)) {
    e2eReportFail($resultsPath.' is not valid JSON.');
}

$stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
$passed = (int) ($stats['expected'] ?? 0);
$failed = (int) ($stats['unexpected'] ?? 0);
$flaky = (int) ($stats['flaky'] ?? 0);
$skipped = (int) ($stats['skipped'] ?? 0);

echo 'Playwright counts: passed='.$passed.' failed='.$failed
    .' flaky='.$flaky.' skipped='.$skipped.PHP_EOL;

if ($passed + $failed + $flaky === 0) {
    e2eReportFail('Playwright executed zero tests.');
}

/**
 * Flatten the suite tree into one row per spec.
 *
 * @return array<int, array{file: string, title: string, line: int, statuses: array<int, string>, expected: array<int, string>}>
 */
function e2eReportSpecs(array $data): array
{
    $specs = [];

    $walk = function (array $suite, string $file) use (&$walk, &$specs): void {
        $file = (string) ($suite['file'] ?? $file);

        foreach ($suite['specs'] ?? [] as $spec) {
            $tests = is_array($spec['tests'] ?? null) ? $spec['tests'] : [];

            $specs[] = [
                'file' => $file,
                'title' => (string) ($spec['title'] ?? ''),
                'line' => (int) ($spec['line'] ?? 0) + 1,
                'statuses' => array_map(
                    static fn ($test): string => (string) ($test['status'] ?? ''),
                    $tests,
                ),
                'expected' => array_map(
                    static fn ($test): string => (string) ($test['expectedStatus'] ?? ''),
                    $tests,
                ),
            ];
        }

        foreach ($suite['suites'] ?? [] as $child) {
            if (is_array($child)) {
                $walk($child, $file);
            }
        }
    };

    foreach ($data['suites'] ?? [] as $suite) {
        if (is_array($suite)) {
            $walk($suite, (string) ($suite['file'] ?? ''));
        }
    }

    return $specs;
}

$specs = e2eReportSpecs($data);

$failedSpecs = array_values(array_filter(
    $specs,
    static fn (array $spec): bool => in_array('unexpected', $spec['statuses'], true),
));

$didNotRun = array_values(array_filter(
    $specs,
    static fn (array $spec): bool => $spec['statuses'] !== []
        && array_unique($spec['statuses']) === ['skipped']
        && ! in_array('skipped', $spec['expected'], true),
));

$didNotRunCount = count($didNotRun);

// ---- Annotations: one per failed test, with its file and line ----

foreach ($failedSpecs as $spec) {
    $relative = e2eReportRelative($spec['file']);
    $message = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $relative.': '.$spec['title']);

    echo '::error file='.$relative.',line='.$spec['line'].'::'.$message.PHP_EOL;
}

// ---- Step summary ----

$summaryPath = getenv('GITHUB_STEP_SUMMARY');

if (is_string($summaryPath) && $summaryPath !== '') {
    $lines = [
        '## Playwright E2E',
        '',
        '| metric | count |',
        '| --- | --- |',
        '| passed | '.$passed.' |',
        '| failed | '.$failed.' |',
        '| flaky | '.$flaky.' |',
        '| did_not_run | '.$didNotRunCount.' |',
        '| skipped (reported by Playwright) | '.$skipped.' |',
        '',
    ];

    if ($failedSpecs !== []) {
        $lines[] = '### Failed tests';
        $lines[] = '';
        $lines[] = '| test | location |';
        $lines[] = '| --- | --- |';

        foreach ($failedSpecs as $spec) {
            $title = str_replace('|', '\\|', $spec['title']);
            $lines[] = '| '.$title.' | `'.e2eReportRelative($spec['file']).':'.$spec['line'].'` |';
        }

        $lines[] = '';
    }

    file_put_contents($summaryPath, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
}

// ---- The gate ----

echo 'passed='.$passed.' failed='.$failed.' flaky='.$flaky
    .' did_not_run='.$didNotRunCount.' skipped='.$skipped.PHP_EOL;

if ($failed > 0 || $flaky > 0 || $didNotRunCount > 0) {
    e2eReportFail(
        'E2E is not green: '.$failed.' failed, '.$flaky.' flaky, '
        .$didNotRunCount.' did not run (see the step summary and e2e-results.json).',
    );
}

echo 'E2E green: 0 failed, 0 flaky, 0 did not run.'.PHP_EOL;
