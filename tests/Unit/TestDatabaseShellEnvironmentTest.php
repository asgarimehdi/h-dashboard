<?php

namespace Tests\Unit;

use App\Support\Testing\TestDatabaseResolver;
use Tests\TestCase;

covers(TestDatabaseResolver::class);

uses(TestCase::class);

/*
 * Inside a booted Laravel process, `.env` values have already been putenv'd,
 * so getenv() can no longer tell a developer's shell export from the app's own
 * environment file. Reading the wrong one makes the preflight report
 * "wrong database" on a machine that is configured perfectly — which is the
 * failure mode a safety gate must not have.
 *
 * /proc/self/environ is the only source that separates them: it holds the
 * environment as inherited at exec time, and putenv() never touches it.
 */

test('a value putenv by the running application is not treated as a shell export', function () {
    // This is exactly what Laravel does with DB_DATABASE while booting — but
    // DB_DATABASE itself cannot be the probe. Under `test --parallel` the
    // workers are exec'd AFTER the parent has putenv'd phpunit.xml/.env, so a
    // worker's exec-time /proc/self/environ legitimately contains it and the
    // assertion would depend on how the suite was started (CI runs parallel;
    // a local run may not). A key nothing else sets isolates the mechanism:
    // putenv() never rewrites the exec-time block.
    putenv('H_DASHBOARD_TEST_PROBE=from_the_env_file');

    // The probe must be live in the process environment, otherwise the
    // assertion below would pass without testing anything.
    expect(getenv('H_DASHBOARD_TEST_PROBE'))->toBe('from_the_env_file');

    $environment = TestDatabaseResolver::shellEnvironment();

    // If putenv'd values leaked in, the preflight would resolve the .env
    // database and refuse to run a suite that is perfectly configured.
    expect($environment)->not->toHaveKey('H_DASHBOARD_TEST_PROBE');
})->after(fn () => putenv('H_DASHBOARD_TEST_PROBE'));

test('an explicit environment snapshot wins over the live process environment', function () {
    // The wrapper captures the environment before Laravel boots and hands it
    // over, so the answer does not depend on when the process started.
    putenv('DB_DATABASE=from_the_env_file');
    putenv('VERIFY_SHELL_ENV='.json_encode(['DB_DATABASE' => 'from_the_shell']));

    $environment = TestDatabaseResolver::shellEnvironment();

    expect($environment['DB_DATABASE'])->toBe('from_the_shell');
})->after(function () {
    putenv('DB_DATABASE');
    putenv('VERIFY_SHELL_ENV');
});

test('a malformed environment snapshot is ignored rather than fatal', function () {
    // A broken snapshot must not be able to block the suite.
    putenv('VERIFY_SHELL_ENV={not json');

    expect(TestDatabaseResolver::shellEnvironment())->toBeArray();
})->after(fn () => putenv('VERIFY_SHELL_ENV'));

test('the environ block parser reads the NUL separated format', function () {
    expect(TestDatabaseResolver::parseEnvironBlock("DB_DATABASE=one\0APP_ENV=testing\0"))
        ->toBe(['DB_DATABASE' => 'one', 'APP_ENV' => 'testing']);
});

test('a snapshot value that decodes to an array is not converted to a string', function () {
    // Docker-based dev environments really do export values like
    // TERMINAL_DOCKER_EXTRA_ARGS=[], which json_decode turns into an array.
    // Casting that to string raises "Array to string conversion" and takes the
    // preflight down on a machine that is configured correctly.
    putenv('VERIFY_SHELL_ENV='.json_encode(['DB_DATABASE' => 'h_dashboard_test', 'DOCKER_ARGS' => []]));

    expect(TestDatabaseResolver::shellEnvironment()['DB_DATABASE'])->toBe('h_dashboard_test');
})->after(fn () => putenv('VERIFY_SHELL_ENV'));
