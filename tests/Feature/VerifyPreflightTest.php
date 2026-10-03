<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyPreflight;
use App\Support\Testing\TestDatabaseResolver;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(VerifyPreflight::class);

class VerifyPreflightTest extends TestCase
{
    use InteractsWithTestSetup;

    /**
     * The preflight is a gate on the environment, so its own tests have to
     * drive it the way the wrapper does: through the shell environment the
     * resolver reads, not by mutating config() — otherwise the tests would
     * pass while the real failure mode (an exported DB_DATABASE) went
     * unexercised.
     */
    private function withShellEnvironment(array $environment): static
    {
        app()->instance(TestDatabaseResolver::class, new TestDatabaseResolver(base_path(), $environment));

        return $this;
    }

    public function test_it_passes_when_the_resolved_database_is_the_test_database(): void
    {
        $this->withShellEnvironment([]);

        $this->artisan('verify:preflight')
            ->expectsOutputToContain('h_dashboard_test')
            ->assertExitCode(VerifyPreflight::EXIT_OK);
    }

    public function test_it_fails_with_exit_code_two_when_an_exported_variable_points_elsewhere(): void
    {
        // The trap the wrapper exists for: the wrong database is otherwise
        // reported as a scatter of failures from green-looking tests — and
        // migrate:fresh would drop the developer's real data.
        $this->withShellEnvironment(['DB_DATABASE' => 'h_dashboard']);

        // Neither substring may contain the other: PendingCommand matches each
        // written line against its expectations in order, so a short substring
        // registered first swallows the line and the second never matches.
        $this->artisan('verify:preflight')
            ->expectsOutputToContain('expected [h_dashboard_test]')
            ->expectsOutputToContain('force="true"')
            ->assertExitCode(VerifyPreflight::EXIT_PRECONDITION_FAILED);
    }

    public function test_it_fails_with_exit_code_two_when_the_test_database_does_not_exist(): void
    {
        // A missing database must be one clear precondition failure, not
        // "database does not exist" repeated across otherwise green tests.
        // The expected name has to be the absent one too — the name is checked
        // before the server is probed, so a mismatch short-circuits.
        config()->set('verify.test_database', 'h_dashboard_test_absent');
        $this->withShellEnvironment(['DB_DATABASE' => 'h_dashboard_test_absent']);

        $this->artisan('verify:preflight')
            ->expectsOutputToContain('h_dashboard_test_absent] does not exist')
            ->expectsOutputToContain('CREATE DATABASE')
            ->assertExitCode(VerifyPreflight::EXIT_PRECONDITION_FAILED);
    }

    public function test_it_fails_with_exit_code_two_when_the_driver_is_not_pgsql(): void
    {
        $this->withShellEnvironment([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => 'h_dashboard_test',
        ]);

        $this->artisan('verify:preflight')
            ->expectsOutputToContain('pgsql')
            ->assertExitCode(VerifyPreflight::EXIT_PRECONDITION_FAILED);
    }

    public function test_a_passing_preflight_warns_when_hooks_are_not_active(): void
    {
        // A preflight that passes silently while the light layer is missing is
        // how a whole team ends up pushing unformatted code.
        $this->withShellEnvironment([]);
        config()->set('verify.hooks.expect_installed', false);

        $this->artisan('verify:preflight')
            ->expectsOutputToContain('core.hooksPath is unset')
            ->expectsOutputToContain('composer hooks:install')
            ->assertExitCode(VerifyPreflight::EXIT_OK);
    }

    public function test_the_expected_test_database_name_comes_from_configuration(): void
    {
        // Naming it once in config/verify.php keeps the wrapper, the command
        // and the suite from drifting apart.
        config()->set('verify.test_database', 'h_dashboard_test_custom');
        $this->withShellEnvironment(['DB_DATABASE' => 'h_dashboard_test']);

        $this->artisan('verify:preflight')
            ->expectsOutputToContain('h_dashboard_test_custom')
            ->assertExitCode(VerifyPreflight::EXIT_PRECONDITION_FAILED);
    }
}
