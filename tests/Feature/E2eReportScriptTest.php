<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1469 §5 — `scripts/e2e-report.php` is the only thing that turns the e2e
 * job's Playwright JSON into a red step, because the job itself is
 * `continue-on-error: true` (#867). Its decision table is pinned here, the way
 * `VerifyWrapperScriptTest` pins `verify.sh`: by running the real script
 * against real JSON and real logs.
 *
 * The four statuses Playwright can report are all represented, including the
 * two that both print as `skipped` in the log but mean opposite things:
 *
 *   deliberate `test.skip()` / `test.fixme()` → expectedStatus="skipped"
 *   serial group swallowed after a failure    → expectedStatus="passed"
 */
class E2eReportScriptTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/e2e-report-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandbox.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->sandbox);

        parent::tearDown();
    }

    /**
     * One Playwright spec entry.
     *
     * @return array{title: string, line: int, tests: list<array{status: string, expectedStatus: string}>}
     */
    private function spec(string $title, int $line, string $status, string $expected = 'passed'): array
    {
        return [
            'title' => $title,
            // Playwright reports 0-based lines; the script adds one.
            'line' => $line,
            'tests' => [['status' => $status, 'expectedStatus' => $expected]],
        ];
    }

    /**
     * Build a report with Playwright's own `stats` derived from the specs, so a
     * fixture can never disagree with itself.
     *
     * @param  array<string, list<array{title: string, line: int, tests: list<array{status: string, expectedStatus: string}>}>>  $files
     * @return array<string, mixed>
     */
    private function report(array $files): array
    {
        $stats = ['expected' => 0, 'unexpected' => 0, 'flaky' => 0, 'skipped' => 0];
        $suites = [];

        foreach ($files as $file => $specs) {
            foreach ($specs as $spec) {
                $status = $spec['tests'][0]['status'];
                $stats[$status === 'expected' ? 'expected' : $status]++;
            }

            $suites[] = ['file' => $file, 'specs' => $specs];
        }

        return ['stats' => $stats, 'suites' => $suites];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: int, 1: string, 2: string} exit code, output, step summary
     */
    private function runReport(array $report, ?string $log = "  1 passed (1.0s)\n"): array
    {
        $jsonPath = $this->sandbox.'/e2e-results.json';
        $logPath = $this->sandbox.'/e2e-list.log';
        $summaryPath = $this->sandbox.'/summary.md';

        file_put_contents($jsonPath, json_encode($report));

        if ($log !== null) {
            file_put_contents($logPath, $log);
        }

        $command = 'cd '.escapeshellarg(base_path())
            .' && GITHUB_STEP_SUMMARY='.escapeshellarg($summaryPath)
            .' php '.escapeshellarg(base_path('scripts/e2e-report.php'))
            .' '.escapeshellarg($jsonPath).' '.escapeshellarg($logPath)
            .' 2>&1';

        exec($command, $output, $exit);

        $summary = is_file($summaryPath) ? (string) file_get_contents($summaryPath) : '';

        return [$exit, implode("\n", $output), $summary];
    }

    public function test_a_green_run_passes_and_writes_the_counts_table(): void
    {
        [$exit, $output, $summary] = $this->runReport($this->report([
            'help/help.spec.ts' => [
                $this->spec('opens', 10, 'expected'),
                $this->spec('closes', 20, 'expected'),
            ],
        ]));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('E2E green', $output);

        $this->assertStringContainsString('| passed | 2 |', $summary);
        $this->assertStringContainsString('| failed | 0 |', $summary);
        $this->assertStringContainsString('| flaky | 0 |', $summary);
        $this->assertStringContainsString('| did_not_run | 0 |', $summary);
    }

    public function test_a_failed_test_fails_the_step_and_annotates_its_file_and_line(): void
    {
        // The annotation is the only thing a reviewer sees on the PR page, so it
        // must carry the checkout-relative path — Playwright's JSON stores the
        // path relative to its `testDir` (`tests/e2e`), not to the repo root.
        [$exit, $output, $summary] = $this->runReport($this->report([
            'help/help.spec.ts' => [
                $this->spec('opens', 10, 'expected'),
                $this->spec('closes', 42, 'unexpected'),
            ],
        ]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            '::error file=tests/e2e/help/help.spec.ts,line=43::',
            $output,
            'the annotation must point at tests/e2e/… and the 1-based line'
        );
        $this->assertStringContainsString('| failed | 1 |', $summary);
        $this->assertStringContainsString('| closes | `tests/e2e/help/help.spec.ts:43` |', $summary);
    }

    public function test_a_flaky_test_fails_the_step_even_though_it_eventually_passed(): void
    {
        // The ramp counter is defined as failed == 0 AND flaky == 0, so a test
        // that only passed on retry must not read as green.
        [$exit, $output] = $this->runReport($this->report([
            'help/help.spec.ts' => [$this->spec('opens', 10, 'flaky')],
        ]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('flaky=1', $output);
    }

    public function test_the_tests_a_serial_failure_swallowed_are_counted_as_did_not_run(): void
    {
        // This is the failure #1466 measured: one red test hides the six behind
        // it. They report status="skipped" but were expected to pass.
        [$exit, $output, $summary] = $this->runReport($this->report([
            'theme/custom-poles.spec.ts' => [
                $this->spec('saves poles', 30, 'unexpected'),
                $this->spec('toggles', 40, 'skipped'),
                $this->spec('persists', 50, 'skipped'),
            ],
        ]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('did_not_run=2', $output);
        $this->assertStringContainsString('| did_not_run | 2 |', $summary);
    }

    public function test_deliberate_skips_do_not_count_as_did_not_run(): void
    {
        // The false-red guard. Several specs `test.skip()` themselves on an
        // environment the PR job cannot provide; a deliberate skip is not a
        // hidden failure and must stay green.
        [$exit, $output, $summary] = $this->runReport($this->report([
            'help/help.spec.ts' => [
                $this->spec('opens', 10, 'expected'),
                $this->spec('needs a tile server', 20, 'skipped', 'skipped'),
                $this->spec('fixme later', 30, 'skipped', 'skipped'),
            ],
        ]));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('did_not_run=0', $output);
        $this->assertStringContainsString('| did_not_run | 0 |', $summary);
        $this->assertStringContainsString('| skipped (reported by Playwright) | 2 |', $summary);
    }

    public function test_a_run_that_executed_zero_tests_fails(): void
    {
        // #865: "green with no tests" is the trap this step exists to close.
        [$exit, $output] = $this->runReport($this->report([]));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('executed zero tests', $output);
    }

    public function test_a_missing_json_report_fails_the_step(): void
    {
        $jsonPath = $this->sandbox.'/e2e-results.json';
        $logPath = $this->sandbox.'/e2e-list.log';
        file_put_contents($logPath, "  1 passed (1.0s)\n");

        $command = 'cd '.escapeshellarg(base_path())
            .' && php '.escapeshellarg(base_path('scripts/e2e-report.php'))
            .' '.escapeshellarg($jsonPath).' '.escapeshellarg($logPath).' 2>&1';

        exec($command, $output, $exit);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is missing', implode("\n", $output));
    }

    public function test_a_log_without_a_passed_summary_fails_the_step(): void
    {
        // Without the list reporter's summary line the suite did not run, even
        // if a stale JSON file happens to be on disk.
        [$exit, $output] = $this->runReport(
            $this->report(['help/help.spec.ts' => [$this->spec('opens', 10, 'expected')]]),
            "Playwright is downloading browsers\n",
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('the suite did not run', $output);
    }
}
