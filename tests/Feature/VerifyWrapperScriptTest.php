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
}
