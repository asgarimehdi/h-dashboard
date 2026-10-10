<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #953 — `.env.testing` is tracked AND load-bearing for a local test run.
 *
 * `LoadEnvironmentVariables::checkForSpecificEnvironmentFile()` swaps the env
 * file to `.env.testing` whenever `APP_ENV=testing`, and dotenv's repository is
 * immutable, so `.env.testing`'s `APP_KEY` wins over `.env`'s. With the key
 * blanked in the tracked file, `MissingAppKeyException` kills more than half
 * the suite locally — CI never sees it because every CI job runs
 * `key:generate --env=testing` first, which is exactly why the committed key
 * survived unnoticed.
 *
 * So `composer test` must produce a key for a clone that has none, WITHOUT
 * rotating one it already has: an unconditional `key:generate` rewrites the
 * tracked file on every run and leaves a real key staged in the developer's
 * working tree — the same defect this issue removes.
 *
 * The script is exercised against throwaway fixtures; the last test runs the
 * real `composer test` chain, because only that proves the wiring.
 */
class TestAppKeyBootstrapTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/test-app-key-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    /**
     * The tracked `.env.testing` shape, with the key blanked.
     */
    private function envFileContents(string $appKeyLine = 'APP_KEY='): string
    {
        return "APP_NAME=h-dashboard\nAPP_ENV=local\n{$appKeyLine}\nDB_DATABASE=h_dashboard\n";
    }

    private function writeEnvFile(string $contents): void
    {
        file_put_contents($this->sandbox.'/.env.testing', $contents);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function bootstrap(): array
    {
        $script = base_path('scripts/ensure-test-app-key.php');

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($this->sandbox).' 2>&1',
            $output,
            $exit
        );

        return [$exit, implode("\n", $output)];
    }

    private function storedKey(): ?string
    {
        $contents = (string) file_get_contents($this->sandbox.'/.env.testing');

        return preg_match('/^APP_KEY=(.*)$/m', $contents, $matches) === 1 ? trim($matches[1]) : null;
    }

    public function test_it_writes_a_key_when_the_test_env_file_has_none(): void
    {
        $this->writeEnvFile($this->envFileContents());

        [$exit, $output] = $this->bootstrap();

        $this->assertSame(0, $exit, $output);

        $key = $this->storedKey();

        $this->assertNotNull($key, 'APP_KEY must still be present after the bootstrap');
        $this->assertNotSame('', $key, 'a blank key must be replaced, not left blank');
    }

    public function test_the_generated_key_is_a_usable_encryption_key(): void
    {
        // Laravel's own format and length: `base64:` + a 32-byte key. Anything
        // else fails at the first `Crypt::` call in the suite, which is a much
        // worse error message than a wrong-shape key caught here.
        $this->writeEnvFile($this->envFileContents());

        $this->bootstrap();

        $key = (string) $this->storedKey();

        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $key);
    }

    public function test_it_does_not_rotate_a_key_that_is_already_present(): void
    {
        // The reason this is a script instead of a bare `key:generate` in the
        // composer chain: rotating on every run makes the tracked file dirty
        // forever and puts a fresh real key one `git add` away from a commit.
        $existing = 'base64:EXISTINGKEY000000000000000000000000000000000=';

        $this->writeEnvFile($this->envFileContents('APP_KEY='.$existing));

        [$exit, $output] = $this->bootstrap();

        $this->assertSame(0, $exit, $output);
        $this->assertSame($existing, $this->storedKey(), 'an existing key must be left byte-identical');
    }

    public function test_it_leaves_every_other_line_untouched(): void
    {
        $this->writeEnvFile($this->envFileContents());

        $this->bootstrap();

        $contents = (string) file_get_contents($this->sandbox.'/.env.testing');

        $this->assertStringContainsString('DB_DATABASE=h_dashboard', $contents);
        $this->assertStringContainsString('APP_NAME=h-dashboard', $contents);
        $this->assertSame(1, preg_match('/^APP_KEY=base64:/m', $contents), 'exactly one rewritten key line');
    }

    public function test_it_never_prints_the_key(): void
    {
        $secret = 'base64:EXISTINGKEY000000000000000000000000000000000=';

        $this->writeEnvFile($this->envFileContents('APP_KEY='.$secret));

        [, $output] = $this->bootstrap();

        $this->assertStringNotContainsString('EXISTINGKEY', $output);
    }

    public function test_a_missing_test_env_file_fails_the_precondition_instead_of_writing_one(): void
    {
        // No file at all is a misconfigured clone, not something to paper over
        // by creating a `.env.testing` with a generated key: the file carries
        // database settings too, and a half-file breaks the run in a far more
        // confusing way. Exit 2 = "your machine is wrong" (scripts/verify.sh).
        [$exit, $output] = $this->bootstrap();

        $this->assertSame(2, $exit, $output);
        $this->assertFileDoesNotExist($this->sandbox.'/.env.testing');
    }

    public function test_it_agrees_with_the_leak_guard_about_what_counts_as_empty(): void
    {
        // Two scripts deciding "is this key empty?" is exactly the pair that
        // drifts. `.env.testing` ships `APP_KEY=`, but a quoted empty value
        // parses to empty for Laravel, so both must treat it as empty — or the
        // bootstrap skips a key the suite then cannot boot without.
        foreach (['APP_KEY=""', "APP_KEY=''", 'APP_KEY=   '] as $line) {
            $this->writeEnvFile($this->envFileContents($line));

            $this->bootstrap();

            $this->assertMatchesRegularExpression(
                '/^APP_KEY=base64:/m',
                (string) file_get_contents($this->sandbox.'/.env.testing'),
                "{$line} must be treated as empty by the bootstrap"
            );
        }
    }

    /**
     * The wiring itself: the real `composer test` chain must boot the suite
     * against a keyless tracked `.env.testing`.
     *
     * Runs for real, because only the real chain proves the bootstrap is
     * actually invoked from `scripts.test`.
     *
     * `SecurityHeadersMiddlewareTest` is the fixture, not a convenience: the
     * obvious choice (`tests/Unit/AppBrandTest.php`, which the sibling
     * `VerifyWrapperScriptTest` uses) passes WITH a blank `.env.testing`,
     * because rendering a view component never resolves the encrypter. This
     * file fails with `MissingAppKeyException` — measured: 10 failed in 1.5s —
     * so a green run can only mean the bootstrap ran.
     */
    public function test_the_composer_test_chain_bootstraps_the_key_before_running(): void
    {
        $command = 'cd '.escapeshellarg(base_path()).' && '
            .'COMPOSER_NO_INTERACTION=1 composer test -- tests/Feature/SecurityHeadersMiddlewareTest.php 2>&1';

        exec($command, $output, $exit);
        $output = implode("\n", $output);

        $this->assertStringNotContainsString(
            'No application encryption key has been specified',
            $output,
            'the suite ran without a key, so scripts.test does not bootstrap one'
        );
        $this->assertMatchesRegularExpression('/SecurityHeadersMiddlewareTest/', $output);
        $this->assertMatchesRegularExpression('/10 passed/', $output, $output);
    }
}
