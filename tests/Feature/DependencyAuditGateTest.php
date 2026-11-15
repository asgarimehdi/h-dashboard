<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1506 — `scripts/dependency-audit-gate.php` is the only thing that decides
 * whether `composer audit` fails the CI step, because the CLI cannot express
 * the policy on its own:
 *
 *   - `composer audit` exits 1 for an advisory AND for a hard error (invalid
 *     lock, unreachable advisory DB, unknown repository), so exit code alone
 *     cannot distinguish "vulnerable" from "could not judge";
 *   - `--ignore-severity` is applied only when the severity is a string
 *     (Composer 2.11 `Advisory/Auditor::audit`), so the null-severity
 *     advisories Packagist returns for symfony packages cannot be filtered;
 *   - `--ignore-severity` accepts any value without validation, so one typo
 *     (`moderate` for `medium`) silently turns the filter off.
 *
 * The decision table is pinned here the way `E2eReportScriptTest` pins
 * `scripts/e2e-report.php`: by running the real script against real JSON.
 * Fixtures live inline — the repo has no `tests/fixtures/` directory.
 */
final class DependencyAuditGateTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/dependency-audit-'.bin2hex(random_bytes(6));
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
     * One advisory entry in `composer audit --format=json` shape.
     *
     * @return array<string, mixed>
     */
    private function advisory(string $package, ?string $severity, string $id): array
    {
        return [
            'advisoryId' => $id,
            'packageName' => $package,
            'affectedVersions' => '>=1.0.0,<2.0.0',
            'title' => 'Test advisory for '.$package,
            'cve' => null,
            'link' => 'https://example.test/'.$id,
            'reportedAt' => '2026-11-14 00:00:00',
            'sources' => [],
            'severity' => $severity,
        ];
    }

    /**
     * A report with the given advisories keyed by package name.
     *
     * @param  array<string, list<array<string, mixed>>>  $advisories
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function report(array $advisories = [], array $extra = []): array
    {
        return array_merge(
            ['advisories' => $advisories, 'abandoned' => [], 'filter' => [], 'cooldown' => []],
            $extra,
        );
    }

    /**
     * Write the report to the sandbox and run the gate against it.
     *
     * @param  array<string, mixed>|string  $report  a decoded report, or raw file contents
     * @return array{0: int, 1: string, 2: string} exit code, output, step summary
     */
    private function runGate(array|string $report, string $filename = 'composer-audit.json'): array
    {
        $jsonPath = $this->sandbox.'/'.$filename;

        file_put_contents(
            $jsonPath,
            is_string($report) ? $report : (string) json_encode($report),
        );

        return $this->execGate($jsonPath);
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, output, step summary
     */
    private function execGate(string $jsonPath): array
    {
        $summaryPath = $this->sandbox.'/summary.md';
        $script = base_path('scripts/dependency-audit-gate.php');

        $command = 'cd '.escapeshellarg(base_path())
            .' && GITHUB_STEP_SUMMARY='.escapeshellarg($summaryPath)
            .' php '.escapeshellarg($script)
            .' '.escapeshellarg($jsonPath)
            .' 2>&1';

        exec($command, $output, $exit);

        $summary = is_file($summaryPath) ? (string) file_get_contents($summaryPath) : '';

        return [$exit, implode("\n", $output), $summary];
    }

    public function test_a_critical_advisory_fails_the_step(): void
    {
        [$exit, $output] = $this->runGate($this->report([
            'vendor/critical-package' => [
                $this->advisory('vendor/critical-package', 'critical', 'PKSA-critical-0001'),
            ],
        ]));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('PKSA-critical-0001', $output);
    }

    public function test_a_high_advisory_fails_the_step(): void
    {
        [$exit, $output] = $this->runGate($this->report([
            'vendor/high-package' => [
                $this->advisory('vendor/high-package', 'high', 'PKSA-high-0001'),
            ],
        ]));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('PKSA-high-0001', $output);
    }

    public function test_a_null_severity_advisory_fails_the_step(): void
    {
        // Packagist returns null for the symfony advisories. `--ignore-severity`
        // cannot filter them (the filter runs only on string severities), so a
        // null must never be read as "harmless" — the gate fails closed.
        [$exit, $output] = $this->runGate($this->report([
            'symfony/http-foundation' => [
                $this->advisory('symfony/http-foundation', null, 'PKSA-null-0001'),
            ],
        ]));

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('PKSA-null-0001', $output);
    }

    public function test_medium_and_low_advisories_pass_but_are_still_printed(): void
    {
        // Report-only means printed and summarized, never hidden: a reviewer
        // has to see the advisorids to plan the next lock refresh.
        [$exit, $output, $summary] = $this->runGate($this->report([
            'vendor/medium-package' => [
                $this->advisory('vendor/medium-package', 'medium', 'PKSA-medium-0001'),
            ],
            'vendor/low-package' => [
                $this->advisory('vendor/low-package', 'low', 'PKSA-low-0001'),
            ],
        ]));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('PKSA-medium-0001', $output);
        $this->assertStringContainsString('PKSA-low-0001', $output);
        $this->assertStringContainsString('| medium | 1 |', $summary);
        $this->assertStringContainsString('| low | 1 |', $summary);
    }

    public function test_an_audit_with_no_advisories_passes(): void
    {
        [$exit, $output, $summary] = $this->runGate($this->report());

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('0 advisories', $output);
        $this->assertStringContainsString('| critical | 0 |', $summary);
    }

    public function test_an_empty_advisories_list_passes(): void
    {
        // `composer audit --format=json` writes `"advisories": []` (an array,
        // not an object) when the lock is clean — that shape must not be read
        // as malformed.
        [$exit, $output] = $this->runGate([
            'advisories' => [],
            'abandoned' => [],
            'filter' => [],
            'cooldown' => [],
        ]);

        $this->assertSame(0, $exit, $output);
    }

    public function test_invalid_json_is_undecidable_and_exits_two(): void
    {
        // Exit 2, not 0: an unreadable report must never read as "0 advisories".
        [$exit, $output] = $this->runGate('{"advisories": ');

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('not valid JSON', $output);
    }

    public function test_a_missing_audit_file_exits_two(): void
    {
        [$exit, $output] = $this->execGate($this->sandbox.'/never-written.json');

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('is missing', $output);
    }

    public function test_a_non_empty_unreachable_repositories_list_exits_two(): void
    {
        // An unreachable advisory repository means the list cannot be proven
        // complete — the same fail-closed rule as an unreadable report.
        [$exit, $output] = $this->runGate($this->report([], [
            'unreachable-repositories' => ['https://repo.example.test/packages.json'],
        ]));

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('unreachable', $output);
        $this->assertStringContainsString('https://repo.example.test/packages.json', $output);
    }

    public function test_a_missing_advisories_key_exits_two(): void
    {
        [$exit, $output] = $this->runGate(['abandoned' => []]);

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('advisories', $output);
    }

    public function test_abandoned_packages_are_reported_but_do_not_fail_the_step(): void
    {
        // `--abandoned=report` asks Composer to report without failing; the
        // gate must keep that contract instead of turning an abandoned
        // dependency into a build break.
        [$exit, $output, $summary] = $this->runGate($this->report([], [
            'abandoned' => ['vendor/abandoned-package' => 'vendor/replacement-package'],
        ]));

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('vendor/abandoned-package', $output);
        $this->assertStringContainsString('vendor/abandoned-package', $summary);
    }
}
