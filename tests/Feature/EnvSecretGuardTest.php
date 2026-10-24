<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1035 — a tracked env file must not commit a literal secret either.
 *
 * Issue #953 pinned the APP_KEY; the same repo also carried literal DB/Redis
 * passwords in `.env.example.pgsql`, `.env.testing` and `.env-example-github`,
 * and a literal Zabbix token in `.env.example`. Blanking them is step one; this
 * is the gate that stops them coming back.
 *
 * The rule is deliberately NOT "any non-empty value". `.env.example`
 * legitimately ships `SANCTUM_TOKEN_EXPIRATION` (a duration in minutes) and a
 * throwaway numeric `REDIS_PASSWORD`, and `.env.e2e.example` legitimately ships
 * a `TEST_*` fixture that the e2e specs read through `requireEnv`. A guard that
 * fails those is a guard somebody disables within a week. So a secret-shaped
 * key is acceptable only when its value is one of three layers — empty, a
 * `<placeholder>`, or a `${{ secrets.NAME }}` reference — and anything else is
 * a leak. Names shaped like a test fixture (`TEST_*`) are out of scope on
 * purpose.
 *
 * These tests drive the real script against throwaway git repositories, because
 * "a repo with a literal password in it fails" is a claim a grep over the
 * source cannot prove. Every value written here is fabricated, and the guard is
 * built to never echo one.
 */
class EnvSecretGuardTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/env-secret-guard-'.bin2hex(random_bytes(6));

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
     * Write a tracked env file into the fixture repo and stage it — the guard
     * reads the git index, so an unstaged fixture would be invisible to it.
     */
    private function track(string $name, string $contents): void
    {
        file_put_contents($this->sandbox.'/'.$name, $contents);

        exec('git -C '.escapeshellarg($this->sandbox).' add -- '.escapeshellarg($name).' 2>&1', $ignored, $exit);

        $this->assertSame(0, $exit, "could not stage the fixture file {$name}");
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

    /**
     * The unquoted, trimmed value of the first APP_KEY assignment in a file.
     */
    private function appKeyValue(string $file): string
    {
        $contents = (string) file_get_contents($file);

        if (! preg_match('/^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=[ \t]*(.*)$/m', $contents, $matches)) {
            return '';
        }

        $value = trim($matches[1]);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }

    public function test_a_secret_shaped_key_left_empty_passes(): void
    {
        $this->track('.env.testing', "APP_KEY=\nDB_PASSWORD=\nREDIS_PASSWORD=\nZABBIX_TOKEN=\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'an empty value is the fix, not the defect: '.$output);
    }

    public function test_a_placeholder_secret_value_passes(): void
    {
        // The templates name where the real value lives instead of carrying it.
        $this->track('.env.testing', "DB_PASSWORD=<same-as-dev>\nREDIS_PASSWORD=<set-in-secret-store>\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'a `<placeholder>` is an accepted layer: '.$output);
    }

    public function test_a_github_secrets_expression_passes(): void
    {
        // `.env-example-github` populates its env from the Actions secret store;
        // blanking those expressions would break the template it exists for.
        $this->track('.env-example-github', "DB_PASSWORD=\${{ secrets.DB_PASSWORD }}\nREDIS_PASSWORD=\${{ secrets.REDIS_PASSWORD }}\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'a `${{ secrets.* }}` reference is not a literal: '.$output);
    }

    public function test_non_secret_shaped_keys_are_not_policed(): void
    {
        // The plan's correction to the issue text: these are a duration and a
        // port-style toggle, not credentials. A guard that fails them is a
        // guard that gets switched off.
        $this->track('.env.testing', "SANCTUM_TOKEN_EXPIRATION=1440\nREDIS_PORT=6379\nDB_USERNAME=h_dashboard\nSANCTUM_STATEFUL_DOMAINS=localhost\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'only secret-shaped names are in scope: '.$output);
    }

    public function test_test_fixture_keys_are_exempt(): void
    {
        // `.env.e2e.example` ships TEST_PASSWORD, which the Playwright helpers
        // read through requireEnv(); failing or blanking it breaks e2e.
        $this->track('.env.e2e.example', "TEST_PASSWORD=fake-fixture-value\nTEST_N_CODE=1234567890\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(0, $exit, 'a TEST_* fixture value is not a leaked secret: '.$output);
    }

    public function test_a_literal_secret_value_fails_the_guard(): void
    {
        $this->track('.env.testing', "APP_NAME=h-dashboard\nDB_PASSWORD=fake-literal-password-01\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(1, $exit, 'a literal password in a tracked env file is a leak: '.$output);
        $this->assertStringContainsString('.env.testing', $output);
        $this->assertStringContainsString('DB_PASSWORD', $output, 'the report must name the key, not just the file');
    }

    public function test_the_guard_never_prints_the_literal_it_reports(): void
    {
        // A guard that republishes the secret into every CI log turns the fix
        // into a second exposure.
        $secret = 'fake-literal-password-02';

        $this->track('.env.testing', "REDIS_PASSWORD={$secret}\n");

        [, $output] = $this->guard($this->sandbox);

        $this->assertStringNotContainsString($secret, $output);
    }

    public function test_every_offending_file_and_key_is_reported(): void
    {
        $this->track('.env.example.pgsql', "DB_PASSWORD=fake-literal-password-03\nDB_ROOT_PASSWORD=fake-literal-password-04\n");
        $this->track('.env.testing', "REDIS_PASSWORD=fake-literal-password-05\n");
        $this->track('.env.example', "ZABBIX_TOKEN=fake-literal-token-06\n");

        [$exit, $output] = $this->guard($this->sandbox);

        $this->assertSame(1, $exit, $output);

        foreach (['.env.example.pgsql', '.env.testing', '.env.example'] as $file) {
            $this->assertStringContainsString($file, $output, "{$file} was not reported");
        }

        foreach (['DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD', 'ZABBIX_TOKEN'] as $key) {
            $this->assertStringContainsString($key, $output, "{$key} was not reported");
        }
    }

    public function test_the_real_repository_carries_no_literal_secret_in_a_tracked_env_file(): void
    {
        // The regression itself: run the guard against the actual repository,
        // unstubbed. Fixtures alone would stay green while the literals sit in
        // the tree.
        [$exit, $output] = $this->guard();

        $this->assertSame(
            0,
            $exit,
            "a tracked env file in this repository still carries a literal secret — replace it with a `<placeholder>` (#1035):\n".$output
        );
    }

    /**
     * The guard and the test-key bootstrap must agree about what counts as an
     * empty APP_KEY. `scripts/ensure-test-app-key.php` says so in its own
     * comment: two scripts deciding independently is exactly the pair that
     * drifts, and a disagreement means the bootstrap skips a key the suite then
     * cannot boot without (a blank key fails loudly at boot; a placeholder
     * APP_KEY fails at the encrypter after every other test has passed).
     *
     * So the invariant is: the guard accepts an APP_KEY exactly when the
     * bootstrap considers it empty — which is observable as "the bootstrap had
     * to write a key". A [`<placeholder>`] is the sharp case: the guard must
     * reject it (it is not a usable key) AND the bootstrap must leave it alone,
     * and both are true only while they share one definition of empty.
     */
    public function test_the_guard_and_the_key_bootstrap_agree_about_an_empty_app_key(): void
    {
        $cases = [
            'blank' => '',
            'quoted blank' => '""',
            'whitespace only' => '   ',
            'placeholder' => '<same-as-dev>',
            'generated key' => 'base64:FAKEFAKEFAKEFAKE00000000000000000000000000=',
        ];

        foreach ($cases as $label => $raw) {
            $file = $this->sandbox.'/.env.testing';

            $this->track('.env.testing', "APP_NAME=h-dashboard\nAPP_KEY={$raw}\n");

            [$guardExit, $output] = $this->guard($this->sandbox);

            $before = (string) file_get_contents($file);

            exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/ensure-test-app-key.php'))
                .' '.escapeshellarg($this->sandbox).' 2>&1',
                $bootstrapOutput,
                $bootstrapExit
            );

            $this->assertSame(0, $bootstrapExit, "the bootstrap failed on the {$label} case: ".implode("\n", $bootstrapOutput));

            $guardPasses = $guardExit === 0;

            // The bootstrap rewrites the file only when it finds no key, so a
            // changed file IS "the bootstrap considered this empty".
            $bootstrapped = (string) file_get_contents($file) !== $before;

            $this->assertSame(
                $guardPasses,
                $bootstrapped,
                "the guard and the bootstrap disagree on the {$label} case: the guard "
                .($guardPasses ? 'accepted' : 'rejected').' a key the bootstrap '
                .($bootstrapped ? 'generated' : 'refused to generate').".\n{$output}"
            );

            $this->assertSame(
                $guardPasses,
                $this->appKeyValue($file) === '' || $bootstrapped,
                "the {$label} case left an unusable APP_KEY in .env.testing"
            );
        }
    }
}
