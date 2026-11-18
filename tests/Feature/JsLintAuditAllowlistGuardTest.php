<?php

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Temporary allowlist (2026-11-17) — the `js-lint` audit step passes only when
 * GHSA-fc5q-39xc-vjpf is the sole high/critical advisory in the npm audit.
 *
 * GHSA-fc5q-39xc-vjpf / CVE-2026-112366 is a picomatch ReDoS (high). It landed
 * in the advisory DB on 2026-11-17 — the 16:38 UTC lint run was green, the
 * 18:57 UTC run went red — and no patched picomatch exists yet: 4.0.7 is the
 * latest published version and is itself vulnerable ("through 4.0.7"). The
 * audit's suggested fix is a downgrade of vite 8.3.8 to 6.2.7, so the owner
 * chose a documented allowlist for this one advisory instead (the sanctioned
 * fix in docs/agents/testing.md: a lock refresh or a documented allowlist —
 * never `continue-on-error`, `--ignore-severity` or `npm audit fix --force`).
 *
 * Scope: this guard reads `.github/workflows/test.yml` ONLY. It pins the audit
 * run block byte-for-byte because the step IS the policy: the exact advisory
 * URL, the high/critical filter, the fail on any other advisory, and the
 * fail-closed guard on a missing or malformed report. A substring search for
 * the advisory id would pass a step that also lets other advisories through.
 *
 * Removal: once picomatch 4.0.8 (or a vite release pinning it) lands and the
 * lockfile is refreshed, restore the plain `npm audit` line and delete this
 * guard — the test fails until the step is restored, so the removal cannot be
 * half-done.
 *
 * Hand-parsed like `SweepJobPipefailGuardTest`: `symfony/yaml` is undeclared
 * and the `yaml` extension is absent from every CI `setup-php` block. The end
 * of the job is the next 2-space job header, never a fixed job name.
 */
final class JsLintAuditAllowlistGuardTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/test.yml';

    private const STEP_NAME = "      - name: 'Audit JS dependencies (blocking: high/critical)'";

    private const ALLOWED_ADVISORY_URL = 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf';

    /**
     * The audit run block, pinned byte-for-byte (ten-space indentation included).
     *
     * @var list<string>
     */
    private const RUN_BLOCK = [
        '          npm audit --package-lock-only --audit-level=high --json > npm-audit.json || true',
        '          node -e \'',
        '            const report = require("./npm-audit.json");',
        '            if (!report || typeof report !== "object" || !report.vulnerabilities || typeof report.vulnerabilities !== "object") { console.error("npm audit JSON is missing or malformed — cannot judge."); process.exit(1); }',
        '            const seen = new Set();',
        '            for (const entry of Object.values(report.vulnerabilities)) {',
        '              for (const via of entry.via || []) {',
        '                if (typeof via === "object" && via !== null && (via.severity === "high" || via.severity === "critical")) seen.add(via.url);',
        '              }',
        '            }',
        '            const allowed = "https://github.com/advisories/GHSA-fc5q-39xc-vjpf";',
        '            const others = [...seen].filter((url) => url !== allowed);',
        '            if (others.length) { console.error("Blocking advisories other than GHSA-fc5q-39xc-vjpf:", others.join(" ")); process.exit(1); }',
        '            if (!seen.has(allowed)) console.log("NOTE: GHSA-fc5q-39xc-vjpf is no longer present — remove the temporary exception.");',
        '            else console.log("Audit: only the temporarily allowed GHSA-fc5q-39xc-vjpf is present.");',
        '          \'',
    ];

    /**
     * A1 — the audit run block is the pinned allowlist gate, nothing else.
     */
    public function test_the_audit_run_block_is_the_pinned_allowlist_gate(): void
    {
        $this->assertAuditRunBlockPinned($this->workflow());
    }

    /**
     * A2 — negative control: with the allowed advisory URL swapped for another
     * id in an in-memory copy of the workflow, the A1 assertions must fail.
     * Nothing is written to disk, so the real workflow is untouched.
     */
    public function test_the_guard_rejects_a_copy_whose_allowed_url_was_changed(): void
    {
        $workflow = $this->workflow();
        $step = $this->auditStepBody($this->jsLintJobBody($workflow));

        $url = self::ALLOWED_ADVISORY_URL;

        $this->assertStringContainsString(
            $url,
            $step,
            'precondition: the audit step body has no allowed-advisory URL to swap — the guard cannot be proven.'
        );

        $mutated = str_replace($url, 'https://github.com/advisories/GHSA-0000-0000-0000', $workflow);
        $this->assertNotSame($workflow, $mutated, 'precondition: the in-memory mutation changed nothing.');

        $this->expectException(AssertionFailedError::class);

        $this->assertAuditRunBlockPinned($mutated);
    }

    /**
     * A3 — the forbidden escapes stay forbidden: the step never fixes, ignores
     * or skips the audit, and the job never goes continue-on-error.
     */
    public function test_the_audit_step_has_no_forbidden_escape(): void
    {
        $job = $this->jsLintJobBody($this->workflow());
        $step = $this->auditStepBody($job);

        $this->assertStringNotContainsString(
            'npm audit fix',
            $step,
            'the audit step must never run `npm audit fix` — it mutates the lockfile inside CI.'
        );

        $this->assertStringNotContainsString(
            '--ignore-severity',
            $step,
            'the audit step must never use `--ignore-severity` — unknown values are accepted silently (testing.md).'
        );

        $this->assertStringNotContainsString(
            'continue-on-error',
            $job,
            'the `js-lint` job must never be continue-on-error — a swallowed audit failure would make the allowlist cosmetic.'
        );
    }

    private function assertAuditRunBlockPinned(string $workflow): void
    {
        $job = $this->jsLintJobBody($workflow);

        $this->assertStringNotContainsString(
            'continue-on-error',
            $job,
            'the `js-lint` job must never be continue-on-error — a swallowed audit failure would make the allowlist cosmetic.'
        );

        $step = $this->auditStepBody($job);

        $this->assertStringContainsString(
            'run: |',
            $step,
            'the audit step must be a multi-line `run: |` block; a one-line `run:` cannot carry the allowlist gate.'
        );

        $this->assertSame(
            self::RUN_BLOCK,
            $this->runBlockLines($step),
            'the audit run block must be the pinned allowlist gate byte-for-byte — any change to the advisory URL, the severity filter or the fail-closed guard needs a deliberate update of this test.'
        );
    }

    private function workflow(): string
    {
        $workflow = (string) file_get_contents(base_path(self::WORKFLOW));
        $this->assertNotSame('', $workflow, self::WORKFLOW.' is empty or missing.');

        return $workflow;
    }

    /**
     * The `js-lint` job body: from its 2-space header to the next 2-space job
     * header (or end of file).
     */
    private function jsLintJobBody(string $workflow): string
    {
        $lines = explode("\n", $workflow);
        $start = null;

        foreach ($lines as $index => $line) {
            if ($line === '  js-lint:') {
                $start = $index;
                break;
            }
        }

        $this->assertIsInt($start, self::WORKFLOW.' has no `  js-lint:` job header at two-space indentation.');

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
            self::WORKFLOW.' parsed an empty `js-lint` job — the job header boundary matcher is wrong.'
        );

        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * The audit step body: from its `- name:` line to the next step header.
     */
    private function auditStepBody(string $job): string
    {
        $start = strpos($job, "\n".self::STEP_NAME);
        $this->assertNotFalse($start, self::WORKFLOW.' has no step named `Audit JS dependencies (blocking: high/critical)` inside the `js-lint` job.');

        $start += 1;
        $next = strpos($job, "\n      - name:", $start);

        $step = $next === false ? substr($job, $start) : substr($job, $start, $next - $start);

        $this->assertStringContainsString('run:', $step, 'the audit step has no `run:` key.');

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

        $this->assertIsInt($start, "the audit step has no `        run: |` block opener.\nStep:\n".$step);

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
