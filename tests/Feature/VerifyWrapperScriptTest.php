<?php

namespace Tests\Feature;

use Tests\TestCase;

/*
 * The wrapper is shell, so it is tested the way it will be used: by running it
 * with a stubbed `php` first on PATH. That proves the order of the gates, the
 * exit codes, and — most importantly — that nothing destructive runs when the
 * preflight fails.
 */
class VerifyWrapperScriptTest extends TestCase
{
    private string $sandbox = '';

    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/verify-wrapper-'.bin2hex(random_bytes(6));
        $this->logFile = $this->sandbox.'/calls.log';

        mkdir($this->sandbox.'/bin', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandbox.'/bin/*') ?: [] as $file) {
            unlink($file);
        }

        @unlink($this->logFile);
        @rmdir($this->sandbox.'/bin');
        @rmdir($this->sandbox);

        parent::tearDown();
    }

    /**
     * A fake `php` that records its arguments and exits with the preflight's
     * code, so the wrapper's own logic is the only thing under test.
     */
    private function stubPhp(int $exitCode = 0): void
    {
        $php = $this->sandbox.'/bin/php';
        file_put_contents($php, "#!/bin/bash\necho \"php \$*\" >> ".escapeshellarg($this->logFile)."\nexit {$exitCode}\n");
        chmod($php, 0o755);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runWrapper(): array
    {
        $command = 'PATH='.escapeshellarg($this->sandbox.'/bin').':$PATH '
            .'bash '.escapeshellarg(base_path('scripts/verify.sh')).' 2>&1';

        exec($command, $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    private function calls(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function test_a_failing_preflight_stops_the_wrapper_before_anything_destructive(): void
    {
        // The whole point of the preflight: a mis-resolved database plus
        // `migrate:fresh` would drop the developer's real data. Reaching either
        // after a precondition failure is a data-loss bug, not a slow test.
        $this->stubPhp(2);

        [$exit, $output] = $this->runWrapper();

        $calls = $this->calls();

        // Each assertion is separate: mixing a static-style expectation into an
        // and() chain returns null and kills the rest of the chain.
        $this->assertSame(2, $exit, 'the preflight exit code must reach the caller unchanged');
        $this->assertStringContainsString('Preflight failed', $output);
        $this->assertStringContainsString('artisan verify:preflight', $calls);
        $this->assertStringNotContainsString('migrate', $calls);
        $this->assertStringNotContainsString('pint', $calls);
        $this->assertStringNotContainsString('phpstan', $calls);
        $this->assertStringNotContainsString('composer test', $calls);
    }

    public function test_a_passing_preflight_clears_the_view_cache_ci_also_clears(): void
    {
        // config:clear and route:clear come from `composer test`; view:clear is
        // the one CI has that composer test does not, so the wrapper adds it.
        $this->stubPhp();

        $this->runWrapper();

        $this->assertStringContainsString('artisan view:clear', $this->calls());
    }

    public function test_the_wrapper_announces_every_stage_it_runs(): void
    {
        $this->stubPhp();

        [, $output] = $this->runWrapper();

        // Stage labels are human-facing prose, so match them case-insensitively
        // rather than pinning the capitalisation of a word I chose.
        $this->assertMatchesRegularExpression('/pint/i', $output);
        $this->assertMatchesRegularExpression('/phpstan/i', $output);
    }

    public function test_the_wrapper_reaches_the_suite_after_a_passing_preflight(): void
    {
        $this->stubPhp();

        $this->runWrapper();

        $calls = $this->calls();

        $this->assertStringContainsString('pint --test', $calls);
        $this->assertStringContainsString('phpstan analyse', $calls);
        $this->assertStringContainsString('composer test', $calls);
    }

    // ──────────────────────────────────────────────
    // Issue #900 — `composer test <path>` must reach the runner
    // ──────────────────────────────────────────────

    /**
     * Composer string-appends extra arguments to EVERY element of a script
     * array, so the previous four-element form handed `tests/Unit/` to
     * `php artisan config:clear` and died with
     * `No arguments expected for "config:clear" command` before a single test
     * ran — the fast single-file loop that README.md:71, AGENTS.md:496 and
     * AGENTS.md:620 all document.
     *
     * This asserts the SHAPE of `scripts.test` rather than stubbing `php`:
     * composer is itself a PHP script (`#!/usr/bin/env php`), so the stub this
     * class installs intercepts Composer's own launch and Composer never
     * executes — `test_the_wrapper_reaches_the_suite_after_a_passing_preflight`
     * asserts only that the substring `composer test` appears, and stays green
     * however badly the array is broken.
     *
     * Collapsing the three commands into ONE `&&` shell string is what makes
     * the appended argument land on the last command alone.
     */
    public function test_the_composer_test_script_is_one_shell_entry_so_arguments_reach_the_runner(): void
    {
        $config = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $test = $config['scripts']['test'] ?? null;

        $this->assertIsArray($test, 'scripts.test must stay an array so disableProcessTimeout is callable');
        $this->assertCount(
            2,
            $test,
            'scripts.test must be exactly [disableProcessTimeout, one && shell string]. '
            .'A third element means Composer appends the path argument to it too.'
        );

        // The callable stays a separate entry: it is a PHP callable, not a
        // shell command, and it must not absorb a forwarded argument.
        $this->assertSame('Composer\\Config::disableProcessTimeout', $test[0]);

        $chain = $test[1];
        $this->assertIsString($chain, 'the second entry must be a shell string');
        $this->assertStringNotContainsString(
            "\n",
            $chain,
            'a multi-line entry would be forwarded per line and reintroduce the bug'
        );

        // Order is load-bearing: config:clear before route:clear, and
        // XDEBUG_MODE=off only on the runner — all three gotchas from AGENTS.md.
        $this->assertMatchesRegularExpression(
            '/config:clear\s*&&\s*php artisan route:clear\s*&&\s*XDEBUG_MODE=off php artisan test/',
            $chain,
            'the three gotcha commands must stay chained in that exact order'
        );

        // The regression itself: no element may be a bare clear command, because
        // that is precisely the element Composer appends the path to.
        foreach ($test as $entry) {
            $this->assertNotSame(
                'php artisan config:clear',
                trim((string) $entry),
                'a bare clear command is an element Composer appends the path argument to'
            );
        }
    }

    /**
     * The execution half: the collapsed form must actually forward a path to
     * the runner. It names a real, fast file so a green run proves the path
     * ARRIVED rather than being dropped — a filter matching nothing would print
     * "No tests found" either way, which proves nothing about forwarding.
     *
     * Run for real rather than stubbed, because a stub cannot reach Composer's
     * own launch (see above).
     */
    public function test_composer_test_forwards_a_path_argument_to_the_runner(): void
    {
        $command = 'cd '.escapeshellarg(base_path()).' && '
            .'COMPOSER_NO_INTERACTION=1 composer test -- tests/Unit/AppBrandTest.php'
            .' 2>&1';

        exec($command, $output, $exit);
        $output = implode("\n", $output);

        // The exact pre-fix symptom: the path landed on config:clear.
        $this->assertStringNotContainsString(
            'No arguments expected',
            $output,
            'the path argument reached config:clear — scripts.test is split again'
        );

        // Composer names the entry it aborted on. Before the collapse it named
        // `php artisan config:clear`; now the whole chain is one entry.
        $this->assertStringNotContainsString(
            'php artisan config:clear handling',
            $output,
            'scripts.test aborted before the runner'
        );

        // And the named file really ran, which is what "the argument was
        // forwarded" actually means.
        //
        // Assert on the test's IDENTITY, not on the summary wording: CI runs
        // PHP 8.5, where AppBrandTest emits a PDO deprecation and Pest prints
        // "1 deprecated" instead of "1 passed", so a `/passed/` pattern fails
        // there while passing locally. Naming the file is environment-blind.
        $this->assertMatchesRegularExpression('/AppBrandTest/', $output);
        $this->assertMatchesRegularExpression('/app brand component class exists/i', $output);
    }
}
