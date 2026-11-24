<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1749 — `scripts/assert-prod-dependency-surface.php` is the guard that keeps
 * developer-only tooling out of the `--no-dev` install set.
 *
 * The trap this test exists for: moving `laravel/boost` and `laravel/tinker`
 * to `require-dev` and running `composer update --lock` changes only the
 * lock's `content-hash`. Both packages stay in `packages`, `composer
 * validate --strict --check-lock` still passes, and production still installs
 * them. Check B (reachability of every `packages` entry from the
 * `composer.json` → `require` roots) catches that; check A only names the two
 * policy packages.
 *
 * Run against the real script the way `DependencyAuditGateTest` runs its gate:
 * fixtures are inline, written to a sandbox, and the script is executed for
 * real so its exit code is what is asserted.
 */
final class ProdDependencySurfaceTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/prod-dependency-surface-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandbox.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->sandbox);

        parent::tearDown();
    }

    /**
     * One composer.lock package entry.
     *
     * @param  array<string, string>  $require
     * @return array<string, mixed>
     */
    private function package(string $name, array $require = []): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'library',
            'require' => $require,
        ];
    }

    /**
     * A composer.lock document with the given production/dev packages.
     *
     * @param  list<array<string, mixed>>  $packages
     * @param  list<array<string, mixed>>  $packagesDev
     * @return array<string, mixed>
     */
    private function lock(array $packages, array $packagesDev = []): array
    {
        return [
            'content-hash' => str_repeat('0', 32),
            'packages' => $packages,
            'packages-dev' => $packagesDev,
        ];
    }

    /**
     * Write the fixtures and execute the real guard.
     *
     * @param  array<string, mixed>|string  $composer
     * @param  array<string, mixed>|string  $lock
     * @return array{0: int, 1: string}
     */
    private function runGuard(array|string $composer, array|string $lock): array
    {
        $composerPath = $this->sandbox.'/composer.json';
        $lockPath = $this->sandbox.'/composer.lock';

        file_put_contents($composerPath, is_string($composer) ? $composer : (string) json_encode($composer));
        file_put_contents($lockPath, is_string($lock) ? $lock : (string) json_encode($lock));

        $command = 'php '.escapeshellarg(base_path('scripts/assert-prod-dependency-surface.php'))
            .' '.escapeshellarg($composerPath)
            .' '.escapeshellarg($lockPath)
            .' 2>&1';

        exec($command, $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    public function test_the_repository_as_committed_is_a_clean_production_surface(): void
    {
        $command = 'php '.escapeshellarg(base_path('scripts/assert-prod-dependency-surface.php'))
            .' '.escapeshellarg(base_path('composer.json'))
            .' '.escapeshellarg(base_path('composer.lock'))
            .' 2>&1';

        exec($command, $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertStringContainsString('OK — no developer-only package', implode("\n", $output));
    }

    public function test_the_before_state_fails_and_names_both_packages(): void
    {
        // The pre-fix tree: both packages in require and in the production half
        // of the lock. This is the state the guard must reject.
        [$exit, $output] = $this->runGuard(
            [
                'require' => [
                    'php' => '^8.4',
                    'laravel/boost' => '^2.10',
                    'laravel/framework' => '^13.0',
                    'laravel/tinker' => '^3.0',
                ],
                'require-dev' => [],
            ],
            $this->lock([
                $this->package('laravel/boost', ['psy/psysh' => '*']),
                $this->package('laravel/framework'),
                $this->package('laravel/tinker', ['psy/psysh' => '*']),
                $this->package('psy/psysh'),
            ]),
        );

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('laravel/boost is a production requirement', $output);
        $this->assertStringContainsString('laravel/tinker is a production requirement', $output);
        $this->assertStringContainsString('laravel/boost is in composer.lock', $output);
        $this->assertStringContainsString('laravel/tinker is in composer.lock', $output);
    }

    public function test_a_half_migrated_lock_fails_the_reachability_check(): void
    {
        // composer.json moved to require-dev, but the lock still carries the
        // dev-only packages in `packages`. `composer validate --check-lock`
        // passes on this shape; check B must not.
        [$exit, $output] = $this->runGuard(
            [
                'require' => ['laravel/framework' => '^13.0'],
                'require-dev' => [
                    'laravel/boost' => '^2.10',
                    'laravel/tinker' => '^3.0',
                ],
            ],
            $this->lock(
                [
                    $this->package('laravel/boost', ['psy/psysh' => '*']),
                    $this->package('laravel/framework'),
                    $this->package('laravel/tinker', ['psy/psysh' => '*']),
                    $this->package('psy/psysh'),
                ],
                [
                    $this->package('laravel/boost', ['psy/psysh' => '*']),
                    $this->package('laravel/tinker', ['psy/psysh' => '*']),
                ],
            ),
        );

        $this->assertSame(1, $exit, $output);
        // psy/psysh is not policy-named, so only check B can flag it.
        $this->assertStringContainsString('psy/psysh is in composer.lock → packages but no production requirement reaches it', $output);
    }

    public function test_unreadable_input_exits_two_instead_of_passing(): void
    {
        // An unreadable surface must never read as "clean".
        [$exit, $output] = $this->runGuard('{"require": ', $this->lock([]));

        $this->assertSame(2, $exit, $output);
        $this->assertStringContainsString('is not valid JSON', $output);

        // A missing lock is the same undecidable case.
        $command = 'php '.escapeshellarg(base_path('scripts/assert-prod-dependency-surface.php'))
            .' '.escapeshellarg(base_path('composer.json'))
            .' '.escapeshellarg($this->sandbox.'/never-written.lock')
            .' 2>&1';

        exec($command, $missingOutput, $missingExit);

        $this->assertSame(2, $missingExit, implode("\n", $missingOutput));
        $this->assertStringContainsString('is missing', implode("\n", $missingOutput));
    }
}
