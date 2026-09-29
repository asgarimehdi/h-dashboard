<?php

namespace App\Console\Commands;

use App\Support\Testing\ResolvedTestDatabase;
use App\Support\Testing\TestDatabaseResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;
use Throwable;

/**
 * Environment preflight for `composer verify`.
 *
 * Runs BEFORE any destructive command. A missing test database otherwise shows
 * up as "database h_dashboard_test does not exist" scattered through otherwise
 * green tests — it reads exactly like a flake — and a mis-resolved database
 * means `migrate:fresh` would drop the developer's real data.
 *
 * The exit codes are distinct on purpose: 2 means the environment is not fit to
 * run the suite (a precondition), so a wrapper can tell "your machine is wrong"
 * from "your code is wrong".
 */
class VerifyPreflight extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_PRECONDITION_FAILED = 2;

    /**
     * The database every test database is checked against.
     */
    private const MAINTENANCE_DATABASE = 'postgres';

    protected $signature = 'verify:preflight';

    protected $description = 'Check that this machine can run the test suite the way CI does';

    public function handle(?TestDatabaseResolver $resolver = null): int
    {
        // Bound in tests to drive specific environments; unbound in production,
        // where the real shell environment is the only thing that matters.
        $resolver ??= TestDatabaseResolver::forCurrentProcess();

        $target = $resolver->resolve();
        $expected = (string) config('verify.test_database', 'h_dashboard_test');

        $this->line("Testing database: {$target->describe()}");

        $failures = array_merge(
            $this->checkDriver($target),
            $this->checkName($target, $expected),
        );

        if ($failures === []) {
            $failures = $this->checkServer($target);
        }

        if ($failures !== []) {
            return $this->reportPreconditionFailure($failures, $target);
        }

        $this->warnIfHooksAreMissing();
        $this->info('✅ Preflight passed — this machine can run the suite.');

        return self::EXIT_OK;
    }

    /**
     * @return array<int, string>
     */
    private function checkDriver(ResolvedTestDatabase $target): array
    {
        return $target->driver === 'pgsql'
            ? []
            : ["Driver is [{$target->driver}]; the suite requires pgsql (PostGIS)."];
    }

    /**
     * @return array<int, string>
     */
    private function checkName(ResolvedTestDatabase $target, string $expected): array
    {
        if ($target->database === $expected) {
            return [];
        }

        // The cause goes on its own line: it is the part the developer has to
        // act on, and a message that has to be read (not just spotted) is a
        // message that gets misread.
        return [
            "Database is [{$target->database}]; expected [{$expected}].",
            'An exported DB_DATABASE — or a DB_URL, which overrides every database variable — beats'
                .' the phpunit.xml value, because that <env> entry carries no force="true".',
            'Unset the variable, or set VERIFY_TEST_DATABASE if your test database has another name.',
        ];
    }

    /**
     * Probed over a dedicated connection to the maintenance database, so the
     * check works even when the target database does not exist yet.
     *
     * @return array<int, string>
     */
    private function checkServer(ResolvedTestDatabase $target): array
    {
        try {
            $pdo = $this->maintenanceConnection($target);
        } catch (Throwable $e) {
            return ['Could not reach Postgres at '.$target->describe().': '.$e->getMessage()];
        }

        $quoted = $pdo->quote($target->database);
        $exists = (bool) $pdo
            ->query("SELECT 1 FROM pg_database WHERE datname = {$quoted}")
            ?->fetchColumn();

        if (! $exists) {
            $create = 'psql -h '.escapeshellarg($target->host).' -U '.escapeshellarg($target->username)
                .' -d '.self::MAINTENANCE_DATABASE.' -c '
                .escapeshellarg("CREATE DATABASE {$target->database} WITH OWNER={$target->username} TEMPLATE=template_postgis;");

            return [
                "Database [{$target->database}] does not exist.",
                'Create it once with:',
                '  '.$create,
            ];
        }

        $postgis = $pdo->query("SELECT 1 FROM pg_available_extensions WHERE name = 'postgis'")?->fetchColumn();

        if (! $postgis) {
            return [
                "Postgres at {$target->host} has no postgis extension available.",
                'The migrations create the extension themselves, so this server is a plain',
                'postgres image: use postgis/postgis:16-3.4, as docker-compose does.',
            ];
        }

        return [];
    }

    private function maintenanceConnection(ResolvedTestDatabase $target): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $target->host,
            $target->port ?: 5432,
            self::MAINTENANCE_DATABASE,
        );

        try {
            return new PDO($dsn, $target->username, $target->password);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The pre-commit symlink is created by composer's post-install-cmd, so an
     * already-provisioned machine never gets it and the light layer is silently
     * absent. `composer hooks:install` is the supported path; this warning is
     * how its absence becomes visible.
     */
    private function warnIfHooksAreMissing(): void
    {
        if (config('verify.hooks.expect_installed') === false) {
            $this->reportMissingHooks('unset');

            return;
        }

        $expected = (string) config('verify.hooks.path', '.githooks');
        $configured = $this->hooksPath();

        if ($configured === $expected && is_dir(base_path($expected))) {
            return;
        }

        $this->reportMissingHooks($configured === '' ? 'unset' : $configured);
    }

    private function reportMissingHooks(string $configured): void
    {
        $this->warn("⚠️  Git hooks are not active (core.hooksPath is {$configured}).");
        $this->line('    Run: composer hooks:install');
    }

    private function hooksPath(): string
    {
        $git = base_path('.git');

        if (! is_dir($git) && ! is_file($git)) {
            return '';
        }

        try {
            return trim(Process::run('git', ['config', '--get', 'core.hooksPath'])->output());
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function reportPreconditionFailure(array $failures, ResolvedTestDatabase $target): int
    {
        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $this->line("Resolved target: {$target->describe()}");

        return self::EXIT_PRECONDITION_FAILED;
    }
}
