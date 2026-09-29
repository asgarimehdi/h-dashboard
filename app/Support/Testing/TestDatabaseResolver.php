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
    /**
     * Env keys that describe the connection, in the order they matter.
     *
     * @var array<int, string>
     */
    private const KEYS = ['DB_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

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
     * The current process's environment, the way a shell would hand it to a
     * child process: getenv(), falling back to $_SERVER and $_ENV.
     *
     * @return array<string, string>
     */
    public static function shellEnvironment(): array
    {
        $env = [];

        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }

        return $env + getenv();
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
