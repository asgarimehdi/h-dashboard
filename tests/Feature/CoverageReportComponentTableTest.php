<?php

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Issue #1045 — visibility for the Blade components the coverage gate cannot
 * see.
 *
 * `phpunit.xml`'s `<source>` includes only `app/`, so none of the 61
 * single-file Livewire components under `resources/views/livewire/` (342
 * public methods) can move a coverage number when one of them breaks. #1045
 * adds a REPORT, not a gate — the `--min=80` gate and the denominator are
 * locked by #865 — so these tests pin both halves of that contract: the
 * component table the `coverage-report` job prints, and the lock itself.
 *
 * The expectations are an oracle computed from the repository (the same
 * files, counted independently in PHP) rather than hardcoded numbers, so
 * adding a component or a test never breaks them — a report that stops
 * listing a file, drifts from reality, or loses its worst-first ordering
 * does.
 */
class CoverageReportComponentTableTest extends TestCase
{
    /**
     * Cached across tests in this process: the script greps `tests/` once per
     * component (about two seconds), and every case below reads one output.
     */
    private static ?string $report = null;

    private function report(): string
    {
        if (self::$report !== null) {
            return self::$report;
        }

        exec(
            'bash '.escapeshellarg(base_path('scripts/coverage-report.sh')).' 2>&1',
            $output,
            $exit
        );

        $this->assertSame(
            0,
            $exit,
            "coverage-report.sh must stay informational, it can never fail:\n".implode("\n", $output)
        );

        self::$report = implode("\n", $output);

        return self::$report;
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/test.yml'));
    }

    /**
     * The `coverage-report:` job body — from its key to the next top-level job.
     * The lookahead only matches a line indented by EXACTLY two spaces, which
     * is what a sibling job key looks like; every line inside the block is
     * indented by four or more.
     */
    private function coverageReportJob(): string
    {
        $matched = preg_match('/^  coverage-report:\n(.*?)(?=^  [a-z-]+:)/ms', $this->workflow(), $matches);

        $this->assertSame(1, $matched, 'the coverage-report job vanished from test.yml');

        $block = trim((string) ($matches[1] ?? ''));
        $this->assertNotSame('', $block, 'the coverage-report job block is empty');

        return $block;
    }

    /**
     * Every Blade file under resources/views/livewire, counted independently
     * of the script: `public function` occurrences per file, and test
     * references naming it in either separator spelling (Livewire accepts
     * `tickets.inbox` and `tickets/inbox` for the same component, and strips
     * the ⚡ prefix the way its Finder derives SFC names).
     *
     * @return array<string, array{0: int, 1: int}> component name => [methods, refs]
     */
    private function expectedRows(): array
    {
        $tests = $this->allTestsSource();
        $rows = [];

        foreach ($this->filesUnder('resources/views/livewire') as $path) {
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            $relative = (string) preg_replace(
                '#^.*/resources/views/livewire/#',
                '',
                str_replace('\\', '/', $path)
            );
            $relative = (string) preg_replace('/\.blade\.php$/', '', $relative);

            $slash = (string) preg_replace('/\x{26A1}[\x{FE0E}\x{FE0F}]?/u', '', $relative);
            $dot = str_replace('/', '.', $slash);

            $source = (string) file_get_contents($path);
            $methods = substr_count($source, 'public function');

            $refs = substr_count($tests, "test('".$dot."'");
            if ($slash !== $dot) {
                $refs += substr_count($tests, "test('".$slash."'");
            }

            $rows[$dot] = [$methods, $refs];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $relativeDirectory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($relativeDirectory), FilesystemIterator::SKIP_DOTS)
        );

        $paths = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Everything under tests/ concatenated — the same file set `grep -r`
     * scans, with a newline between files so a match can never span two.
     */
    private function allTestsSource(): string
    {
        $all = '';
        foreach ($this->filesUnder('tests') as $path) {
            $all .= (string) file_get_contents($path)."\n";
        }

        return $all;
    }

    /**
     * The printed table rows, in document order (worst first).
     *
     * @return list<array{name: string, methods: int, refs: int}>
     */
    private function reportRows(): array
    {
        preg_match_all('/^(\S+) +(\d+) +(\d+)$/m', $this->report(), $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $match) {
            $rows[] = [
                'name' => (string) ($match[1] ?? ''),
                'methods' => (int) ($match[2] ?? 0),
                'refs' => (int) ($match[3] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The issue's own smoke check: the reporting job keeps reporting and
     * never gates — `continue-on-error`, the script invocation, and the
     * Clover handoff #1011 introduced: the job no longer re-runs the suite
     * (the `|| true` guard belonged to that removed second run), it downloads
     * the `coverage-xml` artifact the `test` job already uploaded.
     */
    public function test_the_coverage_report_job_stays_non_blocking_and_still_runs_the_script(): void
    {
        $job = $this->coverageReportJob();

        $this->assertStringContainsString(
            'continue-on-error: true',
            $job,
            'the coverage-report job turned blocking: it must never fail the build'
        );

        $this->assertStringContainsString(
            'bash scripts/coverage-report.sh',
            $job,
            'the job no longer runs the reporting entrypoint, so the component table never prints'
        );

        $this->assertStringNotContainsString(
            'vendor/bin/pest',
            $job,
            'the job re-runs the suite: #1011 moved the pest run to the `test` job and hands the Clover over as an artifact'
        );

        $this->assertMatchesRegularExpression(
            '/uses: actions\/download-artifact@\S+\s*\n\s*with:\s*\n\s*name: coverage-xml/',
            $job,
            'the job must download the `coverage-xml` artifact the `test` job uploads: a drift here silently reports "no data" (the #865 trap)'
        );
    }

    /**
     * The #865 lock, which #1045 explicitly does not reopen: the blocking
     * gate stays where it is, and Blade components stay out of the
     * denominator.
     */
    public function test_the_blocking_gate_and_the_coverage_denominator_are_untouched(): void
    {
        $this->assertStringContainsString(
            '--min=80',
            $this->workflow(),
            'the blocking gate changed: #1045 is visibility only (#865 locks the gate)'
        );

        $matched = preg_match('#<source>.*?</source>#s', (string) file_get_contents(base_path('phpunit.xml')), $sourceBlock);

        $this->assertSame(1, $matched, 'phpunit.xml has no <source> block');

        $source = strtolower((string) ($sourceBlock[0] ?? ''));

        $this->assertStringContainsString(
            'app</directory>',
            $source,
            'the app/ include that defines the gate is gone'
        );

        $this->assertStringNotContainsString(
            'livewire',
            $source,
            'Blade components joined the coverage denominator — out of scope for #1045 (#865 locks it)'
        );
    }

    /**
     * The table itself: every Blade file listed, worst first, and totals that
     * match an independent count of the same repository.
     */
    public function test_the_report_lists_every_component_worst_first_with_totals_matching_the_repository(): void
    {
        $expected = $this->expectedRows();
        $rows = $this->reportRows();

        $this->assertStringContainsString(
            'Livewire component visibility',
            $this->report(),
            'the component section of the report is missing'
        );

        $this->assertCount(
            count($expected),
            $rows,
            'the table must list every Blade file under resources/views/livewire'
        );

        // Ordering — the point of "worst first": fewest test references
        // ascending, then most public methods descending within a tie.
        for ($i = 1; $i < count($rows); $i++) {
            $previous = $rows[$i - 1];
            $current = $rows[$i];

            $this->assertGreaterThanOrEqual(
                $previous['refs'],
                $current['refs'],
                "the table is not sorted worst first at \"{$current['name']}\""
            );

            if ($current['refs'] === $previous['refs']) {
                $this->assertLessThanOrEqual(
                    $previous['methods'],
                    $current['methods'],
                    "components with equal test references must break the tie by methods at \"{$current['name']}\""
                );
            }
        }

        foreach ($rows as $row) {
            $this->assertArrayHasKey(
                $row['name'],
                $expected,
                "\"{$row['name']}\" is printed but is not a file under resources/views/livewire"
            );

            $this->assertSame(
                $expected[$row['name']],
                [$row['methods'], $row['refs']],
                "the row for \"{$row['name']}\" drifted from the repository"
            );
        }

        $totalMethods = array_sum(array_column($expected, 0));
        $totalRefs = array_sum(array_column($expected, 1));

        $this->assertStringContainsString(
            sprintf(
                'Totals: %d files, %d public methods, %d test references.',
                count($expected),
                $totalMethods,
                $totalRefs
            ),
            $this->report(),
            'the totals line disagrees with an independent count of the repository'
        );

        // The three components the issue asks to be named explicitly.
        foreach (['hr.personnel-badge', 'hardware._form-create', 'hardware._form-edit'] as $named) {
            $this->assertArrayHasKey($named, $expected, "the issue's named component is gone: {$named}");
            $this->assertArrayHasKey(
                $named,
                array_column($rows, 'methods', 'name'),
                "{$named} is missing from the printed table"
            );
        }
    }

    /**
     * The zero-reference warning: exactly the files no test names — no more
     * (a false alarm nobody can act on) and no fewer (a silent file is the
     * defect #1045 exists to surface). Optional in the expert plan; asserted
     * against the oracle so it stays true as tests are added.
     */
    public function test_the_zero_reference_list_is_exactly_the_files_no_test_names(): void
    {
        $expected = $this->expectedRows();
        $expectedZero = [];

        foreach ($expected as $name => $row) {
            if ($row[1] === 0) {
                $expectedZero[] = $name;
            }
        }

        sort($expectedZero);

        preg_match('/^Zero test references: (\d+) of (\d+) files$/m', $this->report(), $count);
        preg_match_all('/^  - (\S+) \(/m', $this->report(), $listed);

        $listedNames = $listed[1] ?? [];
        sort($listedNames);

        $this->assertSame(
            (string) count($expectedZero),
            (string) ($count[1] ?? null),
            'the zero-reference counter disagrees with an independent count'
        );

        $this->assertSame(
            (string) count($expected),
            (string) ($count[2] ?? null),
            'the zero-reference section reports a total that is not the number of files'
        );

        $this->assertSame(
            $expectedZero,
            $listedNames,
            'the zero-reference warning lists a different set of files than the repository has'
        );

        $this->assertNotEmpty(
            $expectedZero,
            'nothing is zero-reference anymore: keep the warning (it proves nothing is silently untested)'
        );
    }
}
