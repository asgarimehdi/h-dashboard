<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1565 — `PDO::MYSQL_ATTR_SSL_CA` is deprecated as of PHP 8.5
 * (`Pdo\Mysql::ATTR_SSL_CA` replaces it).
 *
 * Laravel `require`s every file under `config/` while booting, so both the
 * `mysql` and `mariadb` blocks were fetched even when the app runs pgsql: on
 * PHP 8.5 with `pdo_mysql` loaded that one constant marked ~99.7% of the
 * suite as `deprecated`, which is why `failOnDeprecation` could not be armed.
 *
 * The fix is upstream's PHP-version ternary, not a guard on the env value:
 * gating "don't deprecate" on "TLS is unconfigured" brings the deprecated
 * fetch back the moment `MYSQL_ATTR_SSL_CA` is actually set. The first test
 * below is the strong one — it reproduces the bug in exactly the environment
 * where it exists and is a no-op elsewhere; the second pins the source shape
 * so the ternary cannot be deleted silently on a non-8.5 machine.
 *
 * Note the canonical spelling: Pint's `fully_qualified_strict_types` rewrites
 * `Pdo\Mysql::ATTR_SSL_CA` to an imported `Mysql::ATTR_SSL_CA` plus
 * `use Pdo\Mysql;` (verified on this tree), and `pint --test` in CI is a
 * blocking gate — so the assertions below pin the import plus the short name.
 */
class DatabaseConfigDeprecationTest extends TestCase
{
    private const DEPRECATION_BITS = E_DEPRECATED | E_USER_DEPRECATED;

    public function test_requiring_the_database_config_raises_no_deprecation(): void
    {
        $captured = [];

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use (&$captured): bool {
            if ($errno & self::DEPRECATION_BITS) {
                $captured[] = $errstr.' ('.$errfile.':'.$errline.')';
            }

            return true;
        });

        try {
            $config = require base_path('config/database.php');
        } finally {
            restore_error_handler();
        }

        $this->assertIsArray($config);
        $this->assertArrayHasKey('mysql', $config['connections']);
        $this->assertArrayHasKey('mariadb', $config['connections']);

        if (PHP_VERSION_ID < 80500 || ! extension_loaded('pdo_mysql')) {
            // The constant is not deprecated below 8.5 and is never evaluated
            // without the driver. Skip loudly: a silent pass would hide the
            // day the driver disappears from the job that carries the gate.
            $this->markTestSkipped('PHP < 8.5 or pdo_mysql not loaded');
        }

        $this->assertSame(
            [],
            $captured,
            'config/database.php raised deprecations on PHP '.PHP_VERSION.': '.implode('; ', $captured)
        );
    }

    public function test_both_mysql_family_connections_use_the_version_aware_ssl_ca_constant(): void
    {
        $source = (string) file_get_contents(base_path('config/database.php'));

        // Layout-independent on purpose: a Pint-clean wrap of the ternary (the
        // natural edit when a connection gains a comment) must not turn this
        // red — measured: Pint accepts the wrapped form and leaves it wrapped.
        $ternary = '/\(PHP_VERSION_ID >= 80500 \? (?:\\\\?Pdo\\\\Mysql|Mysql)::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA\) => env\(\'MYSQL_ATTR_SSL_CA\'\)/';

        $this->assertMatchesRegularExpression(
            '/use Pdo\\\\Mysql;|\\\\?Pdo\\\\Mysql::ATTR_SSL_CA/',
            $source,
            'the Pdo\Mysql class must be referenced — imported or fully qualified'
        );

        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertMatchesRegularExpression(
                $ternary,
                (string) preg_replace('/\s+/', ' ', $this->connectionBlock($source, $connection)),
                "the {$connection} connection must fetch the SSL CA constant through the PHP-version ternary"
            );
        }

        // Every legacy fetch must be part of a version ternary: remove the
        // ternaries and nothing deprecated may be left behind.
        $leftovers = (string) preg_replace($ternary, '', (string) preg_replace('/\s+/', ' ', $source));

        $this->assertStringNotContainsString(
            'PDO::MYSQL_ATTR_SSL_CA',
            $leftovers,
            'PDO::MYSQL_ATTR_SSL_CA is deprecated on PHP 8.5: it may only appear inside the version ternary'
        );
    }

    private function connectionBlock(string $source, string $connection): string
    {
        $header = "\n        '{$connection}' => [";
        $start = strpos($source, $header);

        $this->assertNotFalse($start, "connection block for {$connection} was not found");
        $this->assertSame(1, substr_count($source, $header), "the {$connection} connection block must be unique");

        $next = strpos($source, "\n        '", $start + strlen($header));

        return substr($source, $start, $next === false ? null : $next - $start);
    }
}
