<?php

use Composer\Semver\Semver;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Issue #1062. Three locked packages cap `require.php` below 8.6:
 *   - nette/schema  `8.1 - 8.5` (production, via league/commonmark)
 *   - nette/utils   `8.2 - 8.5` (production, via league/commonmark)
 *   - brianium/paratest              (dev)
 * so the `test-php86` preview job can only install with
 * `--ignore-platform-req=php` — a flag that therefore carries production
 * weight, not just tooling.
 *
 * `dragonmantank/cron-expression` is NOT one of them: its
 * `^8.2|^8.3|^8.4|^8.5` still admits 8.6, because `^8.5` means
 * `>=8.5.0 <9.0.0`.
 *
 * This is the monitoring the issue asked for. When upstream widens a range
 * (GA 8.6 is 2026-11-19), the set stops matching and this fails — pointing
 * back at AGENTS.md and the workflow comment to update, instead of leaving a
 * human reminder to rot.
 */

/**
 * @return array<string, string> package name => 'production'|'dev', for every
 *                               locked package whose `require.php` excludes 8.6
 */
function php86BlockingPackages(string $lockPath): array
{
    $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);

    $blocking = [];

    foreach (['packages' => 'production', 'packages-dev' => 'dev'] as $section => $context) {
        foreach ($lock[$section] as $package) {
            $constraint = $package['require']['php'] ?? null;

            if ($constraint !== null && ! Semver::satisfies('8.6.0', $constraint)) {
                $blocking[$package['name']] = $context;
            }
        }
    }

    ksort($blocking);

    return $blocking;
}

test('exactly three locked packages block PHP 8.6', function () {
    expect(php86BlockingPackages(base_path('composer.lock')))->toBe([
        'brianium/paratest' => 'dev',
        'nette/schema' => 'production',
        'nette/utils' => 'production',
    ]);
});

test('AGENTS.md names every package that blocks PHP 8.6', function () {
    $agents = (string) file_get_contents(base_path('AGENTS.md'));

    foreach (array_keys(php86BlockingPackages(base_path('composer.lock'))) as $package) {
        expect($agents)->toContain($package);
    }
});
