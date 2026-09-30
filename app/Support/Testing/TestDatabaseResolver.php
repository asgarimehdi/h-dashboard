<?php

namespace App\Support\Testing;

use Illuminate\Database\ConfigurationUrlParser;
use SimpleXMLElement;
use Throwable;

/**
 * Resolves the database the test suite will actually use.
 *
 * A wrapper that reads `.env` — or that parses phpunit.xml and assumes its
 * values win — reports the wrong database under exactly the conditions it
 * exists to catch. Three layers sit between the shell and the suite:
 *
 *   1. an exported shell variable, which wins over any <env> entry that lacks
 *      force="true" (see PHPUnit's PhpHandler: `if ($force || getenv($name)
 *      === false)`);
 *   2. phpunit.xml's <env> entries;
 *   3. the application's own configuration (.env through config/database.php).
 *
 * On top of that, DB_URL overrides every individual database variable, via
 * Laravel's own ConfigurationUrlParser — so it is parsed here with the same
 * class rather than a second implementation that could drift.
 */
class TestDatabaseResolver
{
    public function __construct(
        private readonly string $basePath,
        /** @var array<string, string> shell environment to resolve against */
        private readonly array $environment,
    ) {}

    public static function forCurrentProcess(): self
    {
        return new self(base_path(), self::shellEnvironment());
    }

    /**
     * The environment this process was STARTED with — never one the running
     * application has since putenv'd into it.
     *
     * This distinction is the whole reason the preflight can be trusted. By the
     * time an artisan command runs, Laravel has already `putenv`'d every value
     * from `.env`, so `getenv('DB_DATABASE')` returns the app's database whether
     * or not the developer ever exported one. Reading that back would make the
     * gate report "wrong database" on a machine that is configured perfectly.
     *
     * `/proc/self/environ` is the only source that separates the two: it is the
     * environment as inherited at exec time, and `putenv()` never rewrites it.
     * A wrapper that runs php as its first child passes the shell environment
     * explicitly (VERIFY_SHELL_ENV), which is the portable form of the same
     * answer and the preferred path.
     *
     * @return array<string, string>
     */
    public static function shellEnvironment(): array
    {
        $snapshot = getenv('VERIFY_SHELL_ENV');

        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);

            if (is_array($decoded)) {
                $variables = [];

                // Scalars only: a real environment value is text, and
                // json_decode turns a value like TERMINAL_DOCKER_EXTRA_ARGS=[]
                // into an array. Casting that to a string raises "Array to
                // string conversion" and takes the preflight down on a machine
                // that is configured correctly.
                foreach ($decoded as $name => $value) {
                    if (is_string($name) && is_scalar($value)) {
                        $variables[$name] = (string) $value;
                    }
                }

                return $variables;
            }
        }

        return self::parseEnvironBlock((string) @file_get_contents('/proc/self/environ'));
    }

    /**
     * @return array<string, string>
     */
    public static function parseEnvironBlock(string $block): array
    {
        $variables = [];

        foreach (explode("\0", $block) as $entry) {
            if (! str_contains($entry, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $entry, 2);

            if ($name !== '') {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }

    public function resolve(): ResolvedTestDatabase
    {
        return $this->fromEnvironment($this->phpunitVariables() + $this->environment + $this->applicationVariables());
    }

    /**
     * Layer 1: phpunit.xml's <env> entries. A forced entry wins even over an
     * exported shell variable; an unforced one loses to it.
     *
     * @return array<string, string>
     */
    private function phpunitVariables(): array
    {
        $path = $this->basePath.'/phpunit.xml';

        if (! is_file($path)) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement((string) file_get_contents($path));
        } catch (Throwable) {
            return [];
        }

        $variables = [];

        foreach ($xml->xpath('//php/env') ?: [] as $entry) {
            $name = (string) $entry['name'];
            $isForced = filter_var((string) $entry['force'], FILTER_VALIDATE_BOOLEAN);

            if ($name === '' || ($isForced === false && array_key_exists($name, $this->environment))) {
                continue;
            }

            $variables[$name] = (string) $entry['value'];
        }

        return $variables;
    }

    /**
     * Layer 3: the application's own resolved configuration. Only the keys the
     * resolver needs are read, so this stays cheap and never boots a connection.
     *
     * @return array<string, string>
     */
    private function applicationVariables(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            return [];
        }

        $values = [
            'DB_CONNECTION' => is_string($connection) ? $connection : '',
            'DB_HOST' => (string) ($config['host'] ?? ''),
            'DB_PORT' => (string) ($config['port'] ?? ''),
            'DB_DATABASE' => (string) ($config['database'] ?? ''),
            'DB_USERNAME' => (string) ($config['username'] ?? ''),
            'DB_PASSWORD' => (string) ($config['password'] ?? ''),
        ];

        $url = $config['url'] ?? null;

        return is_string($url) && $url !== '' ? ['DB_URL' => $url] + $values : $values;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function fromEnvironment(array $variables): ResolvedTestDatabase
    {
        $config = ['driver' => $variables['DB_CONNECTION'] ?? ''];

        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $env) {
            if (isset($variables[$env]) && $variables[$env] !== '') {
                $config[$key] = $variables[$env];
            }
        }

        // A DB_URL overrides every individual variable, and Laravel resolves it
        // through this same parser — using it here keeps one implementation.
        if (isset($variables['DB_URL']) && $variables['DB_URL'] !== '') {
            $config = (new ConfigurationUrlParser)->parseConfiguration(
                ['driver' => $config['driver']] + $config + ['url' => $variables['DB_URL']]
            );
        }

        return new ResolvedTestDatabase(
            driver: (string) ($config['driver'] ?? ''),
            database: (string) ($config['database'] ?? ''),
            host: (string) ($config['host'] ?? ''),
            port: (int) ($config['port'] ?? 0),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
        );
    }
}
