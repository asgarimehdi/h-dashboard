<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #953 — the committed `APP_KEY` guard.
 *
 * Four git-tracked env files (`.env.example.mysql`, `.env.example.pgsql`,
 * `.env.testing`, `.env-example-github`) each carried the SAME real
 * `APP_KEY`, byte-identical to a developer's working `.env`. An `APP_KEY` is
 * the master secret: `config/session.php` encrypts session payloads with it
 * and signs the cookie MAC, so a holder can mint a cookie for any account.
 *
 * These tests exercise the guard's BEHAVIOUR against real git fixtures, not
 * the text of the script: a grep over the script proves nothing about whether
 * a file with a key in it is actually reported. Every case builds a throwaway
 * repository in a temp dir, runs `scripts/check-app-key-leak.php <root>`
 * against it, and asserts on the exit code plus the reported paths.
 */
class AppKeyLeakGuardTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/app-key-guard-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox, 0o777, true);

        exec('git init -q '.escapeshellarg($this->sandbox).' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, 'the fixture needs a real git repository');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    /**
     * Write a tracked env file into the fixture repo and stage it.
     */
    private function track(string $name, string $contents): void
    {
        file_put_contents($this->sandbox.'/'.$name, $contents);

        exec('git -C '.escapeshellarg($this->sandbox).' add -- '.escapeshellarg($name).' 2>&1', $ignored, $exit);

        $this->assertSame(0, $exit, "could not stage the fixture file {$name}");
    }

    /**
     * A tracked env file the guard must NOT report — the shape a fixed repo
     * is in: `APP_KEY=` present but empty, exactly like `.env.example`.
     */
    private function blankEnvFile(): string
    {
        return "APP_NAME=h-dashboard\nAPP_ENV=local\nAPP_KEY=\nDB_DATABASE=h_dashboard\n";
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function guard(?string $root = null): array
    {
        $script = base_path('scripts/check-app-key-leak.php');

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script)
            .($root !== null ? ' '.escapeshellarg($root) : '')
            .' 2>&1';

        exec($command, $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    public function test_a_repo_whose_tracked_env_files_are_all_blank_passes(): void
    {
        $this->track('.env.example', $this->blankEnvFile());
        $this->track('.env.testing', $this->blankEnvFile());
        $this->track('.env.example.pgsql', $this->blankEnvFile());

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'blank keys must pass: '.$output);
    }

    public function test_a_tracked_env_file_carrying_a_real_key_fails_the_guard(): void
    {
        // The exact defect this issue reports: a non-empty APP_KEY in a tracked
        // env file. The value must never be echoed back — see the next test.
        $this->track('.env.example', $this->blankEnvFile());
        $this->track('.env.testing', "APP_NAME=h-dashboard\nAPP_KEY=base64:SUPERSECRETKEYMATERIALHERE1234567890abcdef=\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(1, $exit, 'a leaked key must fail the guard: '.$output);
        $this->assertStringContainsString('.env.testing', $output);
    }

    public function test_the_guard_never_prints_the_key_value(): void
    {
        // A guard that leaks the secret it is reporting would republish it in
        // every CI log — the fix would become a second exposure.
        $secret = 'base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKKKLLLL=';

        $this->track('.env.testing', "APP_NAME=h-dashboard\nAPP_KEY={$secret}\n");

        [, $output] = $this->guard($this->sandbox);

        $this->assertStringNotContainsString($secret, $output);
        $this->assertStringNotContainsString('AAAABBBBCCCCDDDD', $output);
    }

    public function test_a_quoted_empty_key_is_still_empty(): void
    {
        // `APP_KEY=""` parses to an empty value; treating the quotes as a key
        // would fail every repo whose template quotes the assignment.
        $this->track('.env.testing', "APP_NAME=h-dashboard\nAPP_KEY=\"\"\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'a quoted empty value is an empty key: '.$output);
    }

    public function test_a_whitespace_only_key_is_treated_as_empty(): void
    {
        $this->track('.env.testing', "APP_NAME=h-dashboard\nAPP_KEY=   \n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'whitespace is not a key: '.$output);
    }

    public function test_an_untracked_env_file_holding_a_key_is_not_a_leak(): void
    {
        // A developer's working `.env` is gitignored and MUST hold a real key —
        // that is what the app runs on. Reporting it would make the guard
        // unusable locally and hide the tracked-file case behind noise.
        $this->track('.env.example', $this->blankEnvFile());
        file_put_contents($this->sandbox.'/.env', "APP_KEY=base64:LOCALDEVELOPERKEY000000000000000000000000=\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'an untracked .env is expected to have a key: '.$output);
    }

    public function test_every_offending_file_is_reported_not_just_the_first(): void
    {
        $leaky = "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n";

        $this->track('.env.example.mysql', $leaky);
        $this->track('.env.example.pgsql', $leaky);
        $this->track('.env-example-github', $leaky);
        $this->track('.env.testing', $leaky);

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(1, $exit, $output);

        foreach (['.env.example.mysql', '.env.example.pgsql', '.env-example-github', '.env.testing'] as $file) {
            $this->assertStringContainsString($file, $output, "{$file} was not reported");
        }
    }

    public function test_a_per_clone_key_in_the_working_tree_of_a_tracked_file_is_not_a_leak(): void
    {
        // `.env.testing` is tracked AND is the file `composer test` generates a
        // per-clone key into. Reading the working tree would fail this guard on
        // a correct machine after the very first local test run — and push
        // developers toward committing a generated key, the exact defect #953
        // removes. The scan must read the git INDEX: a key that is not staged
        // is not committed.
        $this->track('.env.testing', $this->blankEnvFile());

        // Working tree now carries a real key; the index still holds the blank.
        file_put_contents($this->sandbox.'/.env.testing', "APP_KEY=base64:PERCLONEGENERATEDKEY00000000000000000=\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'an unstaged key must not fail the guard: '.$output);
    }

    public function test_a_staged_key_fails_the_guard(): void
    {
        // The other half of the pair above: once the key IS staged, it is on
        // its way into the next commit and must be reported.
        $this->track('.env.testing', "APP_KEY=base64:STAGEDKEY000000000000000000000000000000=\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('.env.testing', $output);
    }

    public function test_a_non_env_tracked_file_is_ignored(): void
    {
        // `phpunit.xml` and friends legitimately mention APP_KEY; only files
        // that ARE environment files can leak one.
        $this->track('phpunit.xml', "<php><env name=\"APP_KEY\" value=\"base64:notasourceofleak000000000000=\"/></php>\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'only env files are in scope: '.$output);
    }

    public function test_a_directory_that_is_not_a_git_repository_exits_with_the_precondition_code(): void
    {
        // A sibling of the fixture repo, NOT a subdirectory of it: a subdirectory
        // is still "inside the work tree" and would scan cleanly instead of
        // proving the precondition path.
        $plain = sys_get_temp_dir().'/app-key-guard-plain-'.bin2hex(random_bytes(6));

        mkdir($plain, 0o777, true);
        file_put_contents($plain.'/.env.testing', "APP_KEY=base64:AAAA\n");

        [$exit, $output] = $this->guard($plain);

        exec('rm -rf '.escapeshellarg($plain));

        // 2 is the precondition code this repo already uses (see
        // scripts/verify.sh): "your machine is wrong", not "your code is wrong".
        $this->assertSame(2, $exit, 'an unscannable tree must not read as a pass or a leak: '.$output);
    }

    public function test_the_real_repository_has_no_tracked_env_file_carrying_a_key(): void
    {
        // The regression this issue IS: run the guard against the actual repo,
        // unstubbed. A test that only ever used fixtures would stay green while
        // the four committed keys sat in the tree.
        [$exit, $output] = $this->guard();

        $this->assertSame(
            0,
            $exit,
            "a tracked env file in this repository still carries an APP_KEY — blank it (#953):\n".$output
        );
    }
}
