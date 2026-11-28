<?php

use Composer\Semver\Semver;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Issue #1062 monitor, resolved by #1945. No locked package caps `require.php`
 * below 8.6 any more:
 *   - brianium/paratest v7.29.0 allows `~8.4.0 || ~8.5.0 || ~8.6.0`, reached
 *     through the Pest 5 / PHPUnit 13 test-stack migration.
 * The nette production pair cleared earlier (#1506): nette/schema >=1.4.0 and
 * nette/utils >=4.1.6 allow 8.6 and sit inside today's `^1.2`/`^4.0`.
 *
 * `dragonmantank/cron-expression` is NOT a blocker: its
 * `^8.2|^8.3|^8.4|^8.5` still admits 8.6, because `^8.5` means
 * `>=8.5.0 <9.0.0`.
 *
 * This stays as a regression guard: when a lock update reintroduces a package
 * whose `require.php` excludes 8.6, the set stops matching and this fails —
 * pointing back at the `test-php86` job instead of leaving a human reminder to
 * rot.
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

test('no locked package blocks PHP 8.6', function () {
    expect(php86BlockingPackages(base_path('composer.lock')))->toBe([]);
});
