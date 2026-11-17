<?php

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Issue #1570 — the `sweep-35` run step must not swallow Playwright's exit code.
 *
 * The step used to be a single-line `run: bash scripts/e2e-test.sh … | tee
 * sweep-35.log`. A `run:` block without an explicit `shell:` runs as
 * `bash -e` — no `pipefail` — so the pipeline's status is `tee`'s, always 0.
 * A failing sweep therefore exited 0 at the step level and only the separate
 * assert step could notice.
 *
 * The fix (decision (A) from #1563, ratified 2026-11-17) is Step 1 only:
 * declare `set -o pipefail` in the run block. A failing Playwright run then
 * reddens the run step itself, and the assert step is skipped by its default
 * `if: success()`. No failure scan was added on purpose: `playwright.config.ts`
 * retries twice on CI and `sweep-35.spec.ts` has no per-spec override, so a
 * flaky-then-passing run prints `✘` then `✓`, exits 0, and summarises as
 * `1 flaky` — a marker scan would turn that green run red.
 *
 * Scope: this guard reads `.github/workflows/theme-sweep-35.yml` ONLY. Its
 * smoke-suite equivalent is `SmokeJobPipefailGuardTest.php` (#1563), which
 * pins `.github/workflows/test.yml`; the two pin different jobs and steps.
 * This is a wiring guard, not a browser test: it cannot prove shell
 * semantics, only that the declared text is the pinned two-line block.
 *
 * Hand-parsed like `SmokeJobPipefailGuardTest` / `DocsCiJobsGuardTest`:
 * `symfony/yaml` is undeclared and the `yaml` extension is absent from every
 * CI setup block. The end of the job is the next 2-space job header or end of
 * file — `sweep-35` is the last job, so the EOF branch is the one exercised.
 */
final class SweepJobPipefailGuardTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/theme-sweep-35.yml';

    private const STEP_NAME = '      - name: Run the 35-theme sweep (hard lock)';

    private const ASSERT_STEP_NAME = '      - name: Assert the sweep really executed';

    /**
     * The sweep pipeline, pinned byte-for-byte (argv, reporter and log file
     * name are all part of what CI proves).
     */
    private const SWEEP_PIPELINE = 'bash scripts/e2e-test.sh tests/e2e/theme/sweep-35.spec.ts --reporter=list 2>&1 | tee sweep-35.log';

    /**
     * A1 — the sweep run block declares pipefail before the pinned pipeline,
     * and the job is not `continue-on-error`.
     */
    public function test_the_sweep_run_step_declares_pipefail_with_the_pinned_pipeline(): void
    {
        $this->assertSweepRunStepPinned($this->workflow());
    }

    /**
     * A2 — negative control: with the pipefail line deleted from an in-memory
     * copy of the step, the A1 assertions must fail. Nothing is written to
     * disk, so the real workflow is untouched by this test.
     */
    public function test_the_guard_rejects_a_copy_whose_pipefail_line_was_removed(): void
    {
        $workflow = $this->workflow();
        $step = $this->stepBody($this->sweepJobBody($workflow), self::STEP_NAME);

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
     * A3 — decision (A): the assert step keeps the "green with zero tests"
     * guard and adds no failure scan (`✘` / `N failed`), which would red a
     * legitimately flaky-then-passing run.
     */
    public function test_the_assert_step_is_unchanged_and_has_no_failure_scan(): void
    {
        $assert = $this->stepBody($this->sweepJobBody($this->workflow()), self::ASSERT_STEP_NAME);

        $this->assertStringContainsString(
            "grep -cE '✓|✔|passed \(' sweep-35.log",
            $assert,
            'the assert step lost its executed-test counter (`grep -cE \'✓|✔|passed (\' sweep-35.log`).'
        );

        $this->assertStringContainsString(
            "grep -qE '[0-9]+ passed' sweep-35.log",
            $assert,
            'the assert step lost its `[0-9]+ passed` requirement — a green run with zero passing tests would slip through (#935 class).'
        );

        $this->assertStringContainsString(
            'test "${EXECUTED}" -gt 0',
            $assert,
            'the assert step lost its `test "${EXECUTED}" -gt 0` requirement — a green run with zero executed tests would slip through (#935 class).'
        );

        $this->assertStringNotContainsString(
            '✘',
            $assert,
            'the assert step grew a failure scan (`✘`) — CI retries twice, so a flaky-then-passing run exits 0 with no `failed` token and this scan would red a green report-only run (decision (A)).'
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

        $step = $this->stepBody($job, self::STEP_NAME);

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
     * header, or end of file (this is the last job).
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

        $this->assertIsInt($start, 'theme-sweep-35.yml has no `  sweep-35:` job header at two-space indentation.');

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
            'theme-sweep-35.yml parsed an empty `sweep-35` job — the job header boundary matcher is wrong.'
        );

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * A step body inside the job: from its `- name:` line to the next step
     * header. The sweep step carries an `env:` block (with comments) before
     * `run:`, so nothing here assumes `run:` is the first key.
     */
    private function stepBody(string $job, string $stepName): string
    {
        $start = strpos($job, "\n".$stepName);
        $this->assertNotFalse($start, sprintf('theme-sweep-35.yml has no step header `%s` inside the `sweep-35` job.', trim($stepName)));

        $start += 1;
        $next = strpos($job, "\n      - name:", $start);
        $step = $next === false ? substr($job, $start) : substr($job, $start, $next - $start);

        $this->assertStringContainsString('run:', $step, sprintf('the step `%s` has no `run:` key.', trim($stepName)));

        return $step;
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
