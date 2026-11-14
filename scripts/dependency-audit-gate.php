#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * #1506 — turn `composer audit --format=json` into the CI dependency gate.
 *
 * Why a script and not the CLI alone (verified against Composer 2.11.2):
 *
 *   - `composer audit` exits 1 for an advisory AND for a hard error (invalid
 *     lock, unreachable advisory DB, unknown repository), so the exit code
 *     cannot tell "vulnerable" from "could not judge". Here that distinction
 *     is exit 1 versus exit 2.
 *   - `--ignore-severity` is applied only when the advisory severity is a
 *     string (`Composer\Advisory\Auditor::audit`), so the null-severity
 *     advisories Packagist serves for symfony packages cannot be filtered at
 *     all — `--ignore-severity=unknown` does nothing. Null therefore blocks
 *     (fail closed) instead of being read as "no severity, no problem".
 *   - `--ignore-severity` values are never validated (AuditCommand passes them
 *     straight through), so one typo — `moderate` instead of `medium` — turns
 *     the filter into a no-op on a step that still looks green.
 *
 * Policy: `critical`, `high` and null (also any severity outside the known set)
 * fail the step; `medium` and `low` are printed and summarized but pass.
 * `abandoned` packages are reported and never fail — `--abandoned=report`
 * already asked Composer exactly that.
 *
 * Usage: php scripts/dependency-audit-gate.php [composer-audit.json]
 * Exit:  0 = no blocking advisory, 1 = at least one blocking advisory,
 *        2 = not decidable (missing/invalid JSON, missing `advisories` map,
 *            unreachable advisory repository)
 */
$reportPath = $argv[1] ?? 'composer-audit.json';

/** Report-only severities; anything else — including null — blocks. */
const DEPENDENCY_AUDIT_REPORT_ONLY = ['medium', 'low'];

/** Print a GitHub error annotation and finish with the given status. */
function dependencyAuditAbort(int $status, string $message): never
{
    fwrite(STDERR, '::error::'.$message.PHP_EOL);
    exit($status);
}

/**
 * Read one advisory's severity as a comparable lowercase string or null.
 *
 * Composer types `severity` as `string|null`, and null is a real value served
 * by Packagist — never coalesce it into an empty string and lose the
 * distinction that makes the fail-closed rule work.
 */
function dependencyAuditSeverity(array $advisory): ?string
{
    $severity = $advisory['severity'] ?? null;

    if (! is_string($severity)) {
        return null;
    }

    $severity = strtolower(trim($severity));

    return $severity === '' ? null : $severity;
}

/**
 * Count advisory entries per severity bucket, blocking ones included.
 *
 * @param  array<string, list<array<string, mixed>>>  $advisories
 * @return array{counts: array<string, int>, blocking: int, total: int, entries: list<string>, blockingRows: list<string>}
 */
function dependencyAuditTally(array $advisories): array
{
    $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0];
    $entries = [];
    $blockingRows = [];
    $blocking = 0;
    $total = 0;

    foreach ($advisories as $package => $packageAdvisories) {
        if (! is_array($packageAdvisories)) {
            dependencyAuditAbort(2, 'composer audit JSON: `advisories.'.(string) $package.'` is not a list — the report is malformed.');
        }

        foreach ($packageAdvisories as $advisory) {
            if (! is_array($advisory)) {
                dependencyAuditAbort(2, 'composer audit JSON: an advisory entry for `'.(string) $package.'` is not an object — the report is malformed.');
            }

            $severity = dependencyAuditSeverity($advisory);
            $bucket = $severity === null || ! array_key_exists($severity, $counts) ? 'unknown' : $severity;
            $isBlocking = $severity === null || ! in_array($severity, DEPENDENCY_AUDIT_REPORT_ONLY, true);

            $counts[$bucket]++;
            $total++;

            if ($isBlocking) {
                $blocking++;
            }

            $advisoryId = is_string($advisory['advisoryId'] ?? null) ? $advisory['advisoryId'] : '(no advisory id)';
            $title = is_string($advisory['title'] ?? null) ? $advisory['title'] : '';
            $cve = is_string($advisory['cve'] ?? null) ? ', '.$advisory['cve'] : '';

            $entries[] = sprintf(
                '  %s [%s] %s — %s (%s%s)',
                $isBlocking ? 'BLOCK ' : 'report',
                $bucket,
                (string) $package,
                $title,
                $advisoryId,
                $cve,
            );

            if ($isBlocking) {
                $blockingRows[] = '| `'.(string) $package.'` | '.$bucket.' | '.$advisoryId.' |';
            }
        }
    }

    return ['counts' => $counts, 'blocking' => $blocking, 'total' => $total, 'entries' => $entries, 'blockingRows' => $blockingRows];
}

/**
 * Flatten `abandoned` into printable rows.
 *
 * @param  array<mixed>  $abandoned
 * @return list<string>
 */
function dependencyAuditAbandonedRows(array $abandoned): array
{
    $rows = [];

    foreach ($abandoned as $package => $replacement) {
        $replacement = is_string($replacement) && trim($replacement) !== ''
            ? trim($replacement)
            : 'no replacement suggested';

        $rows[] = '  - '.(string) $package.': '.$replacement;
    }

    return $rows;
}

// ---- Read the report. A report that is not there is not "zero advisories". ----

if (! is_file($reportPath)) {
    dependencyAuditAbort(2, $reportPath.' is missing — composer audit did not write a JSON report.');
}

$report = json_decode((string) file_get_contents($reportPath), true);

if (! is_array($report)) {
    dependencyAuditAbort(2, $reportPath.' is not valid JSON — the audit cannot be judged from it.');
}

$unreachable = $report['unreachable-repositories'] ?? [];

if (! is_array($unreachable)) {
    dependencyAuditAbort(2, 'composer audit JSON: `unreachable-repositories` is not a list — the report is malformed.');
}

if ($unreachable !== []) {
    $repositories = implode(', ', array_map(
        static fn (mixed $repository): string => is_string($repository) ? $repository : (string) json_encode($repository),
        $unreachable,
    ));

    dependencyAuditAbort(2, 'composer audit reports unreachable repositories: '.$repositories.' — the advisory list cannot be proven complete, so the gate fails closed.');
}

$advisories = $report['advisories'] ?? null;

if (! is_array($advisories)) {
    dependencyAuditAbort(2, 'composer audit JSON has no `advisories` map — the report is truncated or its shape changed.');
}

$abandoned = is_array($report['abandoned'] ?? null) ? $report['abandoned'] : [];

// ---- Report every advisory, blocking or not. ----

$tally = dependencyAuditTally($advisories);
$counts = $tally['counts'];

echo 'Composer dependency audit: '.$tally['total'].' advisories on '.count($advisories).' packages'.PHP_EOL;

foreach ($tally['entries'] as $entry) {
    echo $entry.PHP_EOL;
}

echo 'Severity: critical='.$counts['critical'].' high='.$counts['high']
    .' medium='.$counts['medium'].' low='.$counts['low']
    .' unknown='.$counts['unknown'].PHP_EOL;

$abandonedRows = dependencyAuditAbandonedRows($abandoned);

echo 'Abandoned packages (reported, not blocking): '.count($abandoned).PHP_EOL;

foreach ($abandonedRows as $row) {
    echo $row.PHP_EOL;
}

// ---- Step summary, so a green run still shows what to plan next. ----

$summaryPath = getenv('GITHUB_STEP_SUMMARY');

if (is_string($summaryPath) && $summaryPath !== '') {
    $lines = [
        '## Composer dependency audit',
        '',
        '| severity | advisories |',
        '| --- | --- |',
        '| critical | '.$counts['critical'].' |',
        '| high | '.$counts['high'].' |',
        '| medium | '.$counts['medium'].' |',
        '| low | '.$counts['low'].' |',
        '| unknown (null) | '.$counts['unknown'].' |',
        '',
        'Blocking: critical, high and unknown/null. medium and low are report-only.',
        '',
    ];

    if ($tally['blockingRows'] !== []) {
        $lines[] = '### Blocking advisories';
        $lines[] = '';
        $lines[] = '| package | severity | advisory |';
        $lines[] = '| --- | --- | --- |';
        array_push($lines, ...$tally['blockingRows']);
        $lines[] = '';
    }

    if ($abandonedRows !== []) {
        $lines[] = '### Abandoned packages (report-only)';
        $lines[] = '';

        foreach ($abandonedRows as $row) {
            $lines[] = '- '.trim(substr($row, 1));
        }

        $lines[] = '';
    }

    file_put_contents($summaryPath, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
}

// ---- The gate. ----

if ($tally['blocking'] > 0) {
    dependencyAuditAbort(
        1,
        'Composer audit: '.$tally['blocking'].' blocking advisor'.($tally['blocking'] === 1 ? 'y' : 'ies')
        .' (critical, high or null severity) — fix it by refreshing the lock in its own PR. '
        .'Never with `--ignore-severity` (its unknown values are accepted silently) and never with `npm audit fix --force`.',
    );
}

echo 'Composer dependency audit green: no critical, high or null-severity advisory.'.PHP_EOL;
exit(0);
