<?php

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Issue #1563 — the `smoke` run step must not swallow Playwright's exit code.
 *
 * The step used to be a single-line `run: bash scripts/e2e-test.sh … | tee
 * smoke-list.log`. A `run:` block without an explicit `shell:` runs as
 * `bash -e` — no `pipefail` — so the pipeline's status is `tee`'s, always 0.
 * A smoke run of 9 passed / 1 failed still satisfied the assert step's
 * `[0-9]+ passed` + `EXECUTED > 0` checks and the blocking job stayed green.
 *
 * The fix is Step 1 only (decision on #1563, option A): declare
 * `set -o pipefail` in the run block. A failing Playwright run then reddens
 * the run step itself, and the assert step is skipped by its default
 * `if: success()`. No failure scan was added on purpose: `playwright.config.ts`
 * retries twice on CI, so a flaky test prints `✘` and then `✓`, exits 0 and
 * summarises as `N passed, 1 flaky` — a marker scan would turn that green run
 * red.
 *
 * Scope: this guard reads `.github/workflows/test.yml` ONLY. The same defect
 * at `.github/workflows/theme-sweep-35.yml:137` is tracked as a separate
 * follow-up (#1563 out of scope) and is deliberately not asserted here.
 *
 * Hand-parsed like `EnvPrepWiringTest` / `DocsCiJobsGuardTest`: `symfony/yaml`
 * is undeclared and the `yaml` extension is absent from every CI setup block.
 * The end of the job is the next 2-space job header, never a fixed job name —
 * inserting a job between `smoke` and `mutation` must not silently widen this
 * guard to another job.
 */
final class SmokeJobPipefailGuardTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/test.yml';

    private const STEP_NAME = '      - name: Run smoke suite (list reporter proves execution)';

    /**
     * The smoke pipeline, pinned byte-for-byte (argv, worker cap, timeout,
     * reporter and log file name are all part of what CI proves).
     */
    private const SMOKE_PIPELINE = 'bash scripts/e2e-test.sh tests/e2e/smoke/actions-smoke.spec.ts --workers=1 --timeout=60000 --reporter=list 2>&1 | tee smoke-list.log';

    /**
     * A1 — the smoke run block declares pipefail, and the pipeline is intact.
     */
    public function test_the_smoke_run_step_declares_pipefail_with_the_pinned_pipeline(): void
    {
        $this->assertSmokeRunStepPinned($this->workflow());
    }

    /**
     * A2 — negative control: with the pipefail line deleted from an in-memory
     * copy of the workflow, the A1 assertions must fail. Nothing is written to
     * disk, so the real file is untouched by this test.
     */
    public function test_the_guard_rejects_a_copy_whose_pipefail_line_was_removed(): void
    {
        $workflow = $this->workflow();
        $step = $this->smokeRunStepBody($this->smokeJobBody($workflow));

        $line = "          set -o pipefail\n";

        $this->assertStringContainsString(
            $line,
            $step,
            'precondition: the smoke step body has no `          set -o pipefail` line to delete — the guard cannot be proven.'
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

        $this->assertSmokeRunStepPinned($mutated);
    }

    private function assertSmokeRunStepPinned(string $workflow): void
    {
        $step = $this->smokeRunStepBody($this->smokeJobBody($workflow));

        $this->assertStringNotContainsString(
            'continue-on-error',
            $step,
            'the smoke run step must never be continue-on-error — a swallowed failure would make the pipefail fix cosmetic.'
        );

        $this->assertStringContainsString(
            'run: |',
            $step,
            'the smoke run step must be a multi-line `run: |` block; a one-line `run:` cannot declare pipefail.'
        );

        $block = $this->runBlockLines($step);

        $pipefailAt = array_search('          set -o pipefail', $block, true);
        $pipelineAt = array_search('          '.self::SMOKE_PIPELINE, $block, true);

        $this->assertIsInt(
            $pipefailAt,
            sprintf(
                "the smoke run block must contain exactly `          set -o pipefail`.\nGot:\n%s",
                implode("\n", $block)
            )
        );
        $this->assertIsInt(
            $pipelineAt,
            sprintf(
                "the smoke run block must contain the pinned pipeline byte-for-byte:\n          %s\nGot:\n%s",
                self::SMOKE_PIPELINE,
                implode("\n", $block)
            )
        );
        $this->assertLessThan(
            $pipelineAt,
            $pipefailAt,
            '`set -o pipefail` must be declared BEFORE the teed pipeline — after it, the line is a no-op for this run.'
        );

        $this->assertSame(
            ['          set -o pipefail', '          '.self::SMOKE_PIPELINE],
            $block,
            'the smoke run block must be exactly two lines: `set -o pipefail` then the unchanged command.'
        );
    }

    private function workflow(): string
    {
        $workflow = (string) file_get_contents(base_path(self::WORKFLOW));
        $this->assertNotSame('', $workflow, self::WORKFLOW.' is empty or missing.');

        return $workflow;
    }

    /**
     * The `smoke` job body: from its 2-space header to the next 2-space job
     * header (or end of file).
     */
    private function smokeJobBody(string $workflow): string
    {
        $lines = explode("\n", $workflow);
        $start = null;

        foreach ($lines as $index => $line) {
            if ($line === '  smoke:') {
                $start = $index;
                break;
            }
        }

        $this->assertIsInt($start, 'test.yml has no `  smoke:` job header at two-space indentation.');

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
            'test.yml parsed an empty `smoke` job — the job header boundary matcher is wrong.'
        );

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * The run step body: from its `- name:` line to the next step header.
     */
    private function smokeRunStepBody(string $job): string
    {
        $start = strpos($job, "\n".self::STEP_NAME);
        $this->assertNotFalse($start, 'test.yml has no step named `Run smoke suite (list reporter proves execution)` inside the `smoke` job.');

        $start += 1;
        $next = strpos($job, "\n      - name:", $start);
        $step = $next === false ? substr($job, $start) : substr($job, $start, $next - $start);

        $this->assertStringContainsString('run:', $step, 'the smoke run step has no `run:` key.');

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

        $this->assertIsInt($start, "the smoke run step has no `        run: |` block opener.\nStep:\n".$step);

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
