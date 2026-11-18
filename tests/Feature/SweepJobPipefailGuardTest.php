<?php

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Issue #1570 — the `theme-sweep-35` run step must not swallow Playwright's
 * exit code.
 *
 * The step used to be a single-line `run: bash scripts/e2e-test.sh … | tee
 * sweep-35.log`. A `run:` block without an explicit `shell:` runs as `bash -e`
 * — no `pipefail` — so the pipeline's status was `tee`'s, always 0. The step's
 * status therefore said nothing about the sweep it ran.
 *
 * The fix is Step 1 only (decision (A) ratified on #1563 and re-verified here):
 * declare `set -o pipefail` in the run block. A failing Playwright run then
 * reddens the run step itself, and the assert step is skipped by its default
 * `if: success()`. No failure scan was added on purpose: `playwright.config.ts`
 * sets `retries: process.env.CI ? 2 : 0` and `sweep-35.spec.ts` has no
 * per-spec override, so a flaky-then-passing run prints `✘` then `✓`, exits 0
 * and summarises as `1 flaky` — a `✘` / `N failed` scan would turn that green
 * report-only run red.
 *
 * Scope: this guard reads `.github/workflows/theme-sweep-35.yml` ONLY. The
 * smoke equivalent is `SmokeJobPipefailGuardTest.php` (#1563), which pins
 * `test.yml`; the two jobs have different steps, so they are two guards, not
 * duplication.
 *
 * It is a wiring guard, not a browser test: it cannot prove shell semantics,
 * only the declared text. That is why the pin is the exact two-line run block
 * rather than a substring search for `set -o pipefail`.
 *
 * Hand-parsed like `EnvPrepWiringTest` / `DocsCiJobsGuardTest`: `symfony/yaml`
 * is undeclared and the `yaml` extension is absent from every CI `setup-php`
 * block. The end of the job is the next 2-space job header, never a fixed job
 * name — inserting a job above `sweep-35` must not silently widen this guard.
 */
final class SweepJobPipefailGuardTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/theme-sweep-35.yml';

    private const STEP_NAME = '      - name: Run the 35-theme sweep (hard lock)';

    private const ASSERT_STEP_NAME = 'Assert the sweep really executed';

    /**
     * The sweep pipeline, pinned byte-for-byte (argv order, reporter and log
     * file name are all part of what the step claims to do).
     */
    private const SWEEP_PIPELINE = 'bash scripts/e2e-test.sh tests/e2e/theme/sweep-35.spec.ts --reporter=list 2>&1 | tee sweep-35.log';

    /**
     * A1 — the sweep run block declares pipefail, and the pipeline is intact.
     */
    public function test_the_sweep_run_step_declares_pipefail_with_the_pinned_pipeline(): void
    {
        $this->assertSweepRunStepPinned($this->workflow());
    }

    /**
     * A2 — negative control: with the pipefail line deleted from an in-memory
     * copy of the workflow, the A1 assertions must fail. Nothing is written to
     * disk, so the real workflow is untouched by this test.
     */
    public function test_the_guard_rejects_a_copy_whose_pipefail_line_was_removed(): void
    {
        $workflow = $this->workflow();
        $step = $this->sweepRunStepBody($this->sweepJobBody($workflow));

        $line = "          set -o pipefail\n";

        $this->assertStringContainsString(
            $line,
            $step,
            'precondition: the sweep step body has no `          set -o pipefail` line to delete — the guard cannot be proven.'
        );

        $mutatedStep = str_replace($line, '', $step);
        $this->assertStringNotContainsString(
            $line,
            $mutatedStep,
            'precondition: the in-memory copy still contains the pipefail line.'
        );

        $mutated = str_replace($step, $mutatedStep, $workflow);
        $this->assertNotSame($workflow, $mutated, 'precondition: the in-memory mutation changed nothing.');

        $this->expectException(AssertionFailedError::class);

        $this->assertSweepRunStepPinned($mutated);
    }

    /**
     * A3 — decision (A) stays pinned: the assert step proves a run happened
     * (a `passed` **or** `flaky` summary token + at least one executed-test line)
     * and never scans for failures, so a flaky-but-green run is not turned red (#1577).
     */
    public function test_the_assert_step_accepts_a_flaky_summary_and_has_no_failure_scan(): void
    {
        $step = $this->assertStepBody($this->sweepJobBody($this->workflow()));

        $this->assertStringContainsString(
            "grep -cE '✓|✔|passed \(' sweep-35.log",
            $step,
            'the assert step must still count executed-test lines (`EXECUTED`) — dropping it loses the #935 "green with zero tests" guard.'
        );

        $this->assertStringContainsString(
            "grep -qE '[0-9]+ (passed|flaky)' sweep-35.log",
            $step,
            'the assert step must require a success summary token — `N passed` or `N flaky` (#1577: a single flaky-then-pass test prints `1 flaky` and exits 0).'
        );

        $this->assertStringContainsString(
            'test "${EXECUTED}" -gt 0',
            $step,
            'the assert step must still fail when zero tests executed.'
        );

        $this->assertStringNotContainsString(
            '✘',
            $step,
            'the assert step must not scan for failure markers: CI retries twice, so a flaky-then-passing run exits 0 and prints `✘` for the failed attempt — a scan would red a green sweep.'
        );
    }

    private function assertSweepRunStepPinned(string $workflow): void
    {
        $job = $this->sweepJobBody($workflow);

        $this->assertStringNotContainsString(
            'continue-on-error',
            $job,
            'the `sweep-35` job must never be continue-on-error — a swallowed failure would make the pipefail fix cosmetic.'
        );

        $step = $this->sweepRunStepBody($job);

        $this->assertStringContainsString(
            'run: |',
            $step,
            'the sweep run step must be a multi-line `run: |` block; a one-line `run:` cannot declare pipefail.'
        );

        $block = $this->runBlockLines($step);

        $pipefailAt = array_search('          set -o pipefail', $block, true);
        $pipelineAt = array_search('          '.self::SWEEP_PIPELINE, $block, true);

        $this->assertIsInt(
            $pipefailAt,
            sprintf(
                "the sweep run block must contain exactly `          set -o pipefail`.\nGot:\n%s",
                implode("\n", $block)
            )
        );
        $this->assertIsInt(
            $pipelineAt,
            sprintf(
                "the sweep run block must contain the pinned pipeline byte-for-byte:\n          %s\nGot:\n%s",
                self::SWEEP_PIPELINE,
                implode("\n", $block)
            )
        );
        $this->assertLessThan(
            $pipelineAt,
            $pipefailAt,
            '`set -o pipefail` must be declared BEFORE the teed pipeline — after it, the line is a no-op for this run.'
        );

        $this->assertSame(
            ['          set -o pipefail', '          '.self::SWEEP_PIPELINE],
            $block,
            'the sweep run block must be exactly two lines: `set -o pipefail` then the unchanged command.'
        );
    }

    private function workflow(): string
    {
        $workflow = (string) file_get_contents(base_path(self::WORKFLOW));
        $this->assertNotSame('', $workflow, self::WORKFLOW.' is empty or missing.');

        return $workflow;
    }

    /**
     * The `sweep-35` job body: from its 2-space header to the next 2-space job
     * header (or end of file — `sweep-35` is the last job in this workflow).
     */
    private function sweepJobBody(string $workflow): string
    {
        $lines = explode("\n", $workflow);
        $start = null;

        foreach ($lines as $index => $line) {
            if ($line === '  sweep-35:') {
                $start = $index;
                break;
            }
        }

        $this->assertIsInt($start, self::WORKFLOW.' has no `  sweep-35:` job header at two-space indentation.');

        $end = count($lines);
        for ($index = $start + 1; $index < count($lines); $index++) {
            if (preg_match('/^  [A-Za-z0-9_-]+:[ \t]*$/', $lines[$index]) === 1) {
                $end = $index;
                break;
            }
        }

        $this->assertGreaterThan(
            $start + 1,
            $end,
            self::WORKFLOW.' parsed an empty `sweep-35` job — the job header boundary matcher is wrong.'
        );

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * The run step body: from its `- name:` line to the next step header.
     *
     * The step carries an `env:` block with comments before `run: |`, so
     * nothing here may assume `run:` is the first key.
     */
    private function sweepRunStepBody(string $job): string
    {
        $start = strpos($job, "\n".self::STEP_NAME);
        $this->assertNotFalse($start, self::WORKFLOW.' has no step named `Run the 35-theme sweep (hard lock)` inside the `sweep-35` job.');

        $start += 1;
        $next = strpos($job, "\n      - name:", $start);
        $step = $next === false ? substr($job, $start) : substr($job, $start, $next - $start);

        $this->assertStringContainsString('run:', $step, 'the sweep run step has no `run:` key.');

        return $step;
    }

    /**
     * The assert step body: from its `- name:` line to the next step header.
     */
    private function assertStepBody(string $job): string
    {
        $name = '      - name: '.self::ASSERT_STEP_NAME;

        $start = strpos($job, "\n".$name);
        $this->assertNotFalse($start, self::WORKFLOW.' has no step named `'.self::ASSERT_STEP_NAME.'` inside the `sweep-35` job.');

        $start += 1;
        $next = strpos($job, "\n      - name:", $start);

        return $next === false ? substr($job, $start) : substr($job, $start, $next - $start);
    }

    /**
     * Body lines of the step's `run: |` block (ten-space indented), trimmed of
     * blank padding.
     *
     * @return list<string>
     */
    private function runBlockLines(string $step): array
    {
        $lines = explode("\n", $step);
        $start = null;

        foreach ($lines as $index => $line) {
            if ($line === '        run: |') {
                $start = $index + 1;
                break;
            }
        }

        $this->assertIsInt($start, "the sweep run step has no `        run: |` block opener.\nStep:\n".$step);

        $block = [];
        for ($index = $start; $index < count($lines); $index++) {
            if (str_starts_with($lines[$index], '          ')) {
                $block[] = rtrim($lines[$index]);

                continue;
            }

            if (trim($lines[$index]) === '') {
                continue;
            }

            break;
        }

        return $block;
    }
}
