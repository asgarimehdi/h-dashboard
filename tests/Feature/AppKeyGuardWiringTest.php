<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #953 — the guard must actually RUN, in both homes.
 *
 * `scripts/check-app-key-leak.php` alone changes nothing: a guard nothing
 * invokes is documentation with a shebang. Step 3 of the issue asks for a
 * failing job, and there are exactly two places that can fail a run —
 * `scripts/verify.sh` (which `composer verify` runs before every push) and the
 * `lint` job in `.github/workflows/test.yml`. `scripts/verify.sh` alone is not
 * enough: CI never runs it (its jobs are `lint`, `test`, `coverage-report`,
 * `mutation`, `phpstan`), so a guard living only there fails no job.
 *
 * These assertions cover the wiring only — the guard's own behaviour is
 * `AppKeyLeakGuardTest`'s subject.
 */
class AppKeyGuardWiringTest extends TestCase
{
    /**
     * Throwaway directory for the index-copy fixture. Never inside the
     * repository: `GIT_INDEX_FILE` resolves relative paths against the git
     * working directory, so a sandbox inside the tree would be ambiguous.
     */
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/app-key-wiring-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->sandbox));

        putenv('GIT_INDEX_FILE');

        parent::tearDown();
    }

    private function verifyScript(): string
    {
        return (string) file_get_contents(base_path('scripts/verify.sh'));
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/test.yml'));
    }

    public function test_composer_verify_runs_the_guard_before_the_slow_gates(): void
    {
        $verify = $this->verifyScript();

        $this->assertStringContainsString(
            'scripts/check-app-key-leak.php',
            $verify,
            'composer verify never runs the guard, so a leaked key survives a local push'
        );

        // Anchor on the INVOCATIONS, not the words: the header comment
        // mentions `composer test` on line 7 and `pint` in the stage list, so
        // a bare strpos() finds the documentation before the code and makes
        // this pass/fail for the wrong reason.
        $guardAt = strpos($verify, 'php scripts/check-app-key-leak.php');
        $pintAt = strpos($verify, 'if ! vendor/bin/pint --test');
        $pestAt = strpos($verify, 'if ! composer test');

        $this->assertIsInt($guardAt);
        $this->assertIsInt($pintAt);
        $this->assertIsInt($pestAt);

        // Before the expensive stages: a secret should be the first thing a
        // developer hears about, not the last thing after five minutes of
        // Pint, PHPStan and the whole suite.
        $this->assertLessThan($pintAt, $guardAt, 'the guard must run before Pint');
        $this->assertLessThan($pestAt, $guardAt, 'the guard must run before the suite');
    }

    public function test_a_failing_guard_stops_composer_verify_with_exit_one(): void
    {
        $verify = $this->verifyScript();

        // The dead-test-file guard (issue #935) is the established shape in
        // this file, so the new step must match it: `if ! …; then … exit 1`.
        // Without the non-zero exit the script would continue into the suite
        // and the failure would be invisible.
        // No semicolons: the sibling step is newline-separated
        // (`echo ""` / `echo "…"` / `exit 1`), and a `\s*;` between the parts
        // would never match the code that actually exists.
        $pattern = '/if \! php scripts\/check-app-key-leak\.php; then\s+echo\s+""\s*echo\s+"[^"]*"\s*exit 1\s*fi/';

        $this->assertMatchesRegularExpression(
            $pattern,
            $verify,
            'the guard step must fail the wrapper on a non-zero exit, exactly like the dead-test-files step'
        );
    }

    public function test_the_lint_job_runs_the_guard_so_ci_fails_on_a_leak(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString(
            'scripts/check-app-key-leak.php',
            $workflow,
            'CI never runs the guard, so step 3 of the issue ("fail the job") is unmet'
        );
    }

    /**
     * The CI step's actual behaviour, against the REAL repository index.
     *
     * Every other case here reads script text, and the fixture cases in
     * `AppKeyLeakGuardTest` build their own repositories. Neither proves that
     * the job fails on THIS repo's four files — which is what step 3 of the
     * issue asks for ("fail the job").
     *
     * Uses `GIT_INDEX_FILE` on a COPY of the real index, so the working index
     * is never modified: staging a leak and committing it if the process died
     * between the two lines is not a risk worth taking in a test.
     */
    public function test_the_real_repository_index_fails_the_guard_when_a_key_is_staged(): void
    {
        $root = base_path();
        $realIndex = $root.'/.git/index';

        $this->assertFileExists($realIndex, 'this test needs a real git index');

        $alternate = $this->sandbox.'/index';

        copy($realIndex, $alternate);

        // A tracked template carrying a key — the exact defect, staged for a
        // commit the guard must refuse to let pass. Written at the repository
        // root and REMOVED in the `finally` below: `git add` has to see it on
        // disk, and leaving it there would shadow the real template.
        $leak = "APP_NAME=h-dashboard\nAPP_KEY=base64:STAGEDLEAK00000000000000000000000000000=\n";
        $fixture = $root.'/.env.example.mysql';
        $realContents = (string) file_get_contents($fixture);

        file_put_contents($fixture, $leak);

        $gitIndex = 'GIT_INDEX_FILE='.escapeshellarg($alternate);

        try {
            exec($gitIndex.' git -C '.escapeshellarg($root).' add -- .env.example.mysql 2>&1', $ignored, $exit);

            $this->assertSame(0, $exit, 'could not stage the fixture into the alternate index');

            // Same environment the CI `lint` job runs under: a fresh checkout
            // has every file in the index and nothing else on disk.
            putenv('GIT_INDEX_FILE='.$alternate);

            exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/check-app-key-leak.php')).' 2>&1',
                $output,
                $guardExit
            );
        } finally {
            putenv('GIT_INDEX_FILE');

            file_put_contents($fixture, $realContents);
        }

        $this->assertSame(1, $guardExit, implode("\n", $output));
        $this->assertStringContainsString('.env.example.mysql', implode("\n", $output));

        // And the repository's OWN index is untouched — read it WITHOUT the
        // GIT_INDEX_FILE prefix, since that prefix is what selects the copy.
        exec('git -C '.escapeshellarg($root).' show :.env.example.mysql 2>&1', $staged, $showExit);

        $this->assertSame(0, $showExit);
        $this->assertStringNotContainsString(
            'STAGEDLEAK',
            implode("\n", $staged),
            'the test wrote into the repository\'s own index'
        );

        // Belt and braces: the working tree copy must be restored too, or the
        // real template would be left holding a fake key on disk.
        $this->assertSame(
            $realContents,
            (string) file_get_contents($fixture),
            'the real template was not restored after the fixture'
        );
    }

    /**
     * `php_unit_method_casing` rewrites any method whose name starts with `test`
     * to snake_case — including a private helper that merely begins with those
     * four letters. It rewrote `testEnvFile()` to `test_env_file()` while leaving
     * every `$this->testEnvFile()` call site in camelCase, so the method vanished
     * and six tests died with `Call to undefined method …::testEnvFile()` in the
     * FULL suite run, long after `composer pint` reported success.
     *
     * A helper named `test*` in a PHPUnit class is a landmine; this names the ones
     * already fixed and keeps the next author from reintroducing the shape.
     */
    public function test_no_test_helper_starts_with_the_word_test(): void
    {
        $offenders = [];

        foreach (glob(base_path('tests/Feature/*Test.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // Match the DEFINITION only — a private/protected helper, not a
            // `public function test_…` case (those are the tests themselves).
            preg_match_all(
                '/(?:private|protected)\s+function\s+(test[A-Za-z_]*)\s*\(/i',
                $source,
                $matches
            );

            foreach ($matches[1] as $name) {
                $offenders[] = basename($file).': '.$name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "a non-test helper named test* gets silently renamed by Pint's php_unit_method_casing,\n"
            ."leaving its call sites broken. Rename it (e.g. envFileContents, not testEnvFile):\n"
            .implode("\n", $offenders)
        );
    }

    public function test_the_workflow_guard_step_is_a_run_block_not_a_comment(): void
    {
        // A commented-out step is a step CI ignores while a reader believes the
        // gate exists — the exact failure mode `references/api-endpoints.md`
        // records for the removed `X-XSS-Protection` header.
        $workflow = $this->workflow();

        foreach (explode("\n", $workflow) as $number => $line) {
            if (! str_contains($line, 'scripts/check-app-key-leak.php')) {
                continue;
            }

            $this->assertStringNotContainsString(
                '#',
                $line,
                'workflow line '.($number + 1).' references the guard but is commented out: '.trim($line)
            );
        }

        $this->assertMatchesRegularExpression(
            '/- name:[^\n]*\n\s+run:[^\n]*scripts\/check-app-key-leak\.php/',
            $workflow,
            'the guard must appear as a real `run:` step in the workflow'
        );
    }
}
