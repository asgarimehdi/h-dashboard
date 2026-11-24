#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * #1749 — fail if a developer-only tool is shipped as a production dependency.
 *
 * `laravel/tinker` (an interactive REPL) and `laravel/boost` (a local MCP
 * server whose tools include `DatabaseQuery`) sit in `require` today, so
 * `composer install --no-dev` on every production host installs both. Neither
 * is used by the running application: the only consumers are developer
 * tooling (`scripts/boost_tool.php`, `.mcp.json`). They belong in
 * `require-dev`.
 *
 * Two checks, because the obvious one is not enough:
 *
 *   A. **Policy.** `laravel/boost` and `laravel/tinker` must not appear in
 *      `composer.json` → `require`, nor in `composer.lock` → `packages`
 *      (the production half of the lock; `packages-dev` is fine).
 *
 *   B. **Consistency.** Every entry in `composer.lock` → `packages` must be
 *      reachable from the `composer.json` → `require` roots through the
 *      lock's own `require` edges. This is the trap in this issue: moving two
 *      lines in `composer.json` and running `composer update --lock` changes
 *      only `content-hash` — both packages stay in `packages`, the app still
 *      installs them with `--no-dev`, and `composer validate --strict
 *      --check-lock` passes. A policy check alone names two packages; the
 *      reachability check also catches every transitive package that was left
 *      behind (for this change: `psy/psysh`, `laravel/mcp`, `laravel/roster`,
 *      `nikic/php-parser`, `symfony/yaml`).
 *
 * `provide`/`replace` names are followed when building the graph, so a virtual
 * requirement (`psr/log-implementation`, `illuminate/*` from
 * `laravel/framework`) does not make a correctly placed package look
 * unreachable.
 *
 * Usage:  php scripts/assert-prod-dependency-surface.php [composer.json] [composer.lock]
 * Exit:   0 = healthy, 1 = violation, 2 = an input could not be read (the
 *         surface cannot be proven, so the step fails closed)
 */
$composerPath = $argv[1] ?? dirname(__DIR__).'/composer.json';
$lockPath = $argv[2] ?? dirname(__DIR__).'/composer.lock';

/** Developer-only packages that must never be installed by `--no-dev` (#1749). */
const DEV_ONLY_PACKAGES = ['laravel/boost', 'laravel/tinker'];

/**
 * Print an error annotation and stop.
 */
function prodSurfaceAbort(int $status, string $message): never
{
    fwrite(STDERR, '::error::'.$message.PHP_EOL);

    exit($status);
}

/**
 * Decode a JSON object from disk, or fail closed.
 *
 * @return array<string, mixed>
 */
function prodSurfaceReadJson(string $path, string $label): array
{
    if (! is_file($path)) {
        prodSurfaceAbort(2, $label.' is missing: '.$path);
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        prodSurfaceAbort(2, $label.' could not be read: '.$path);
    }

    $decoded = json_decode($contents, true);

    if (! is_array($decoded)) {
        prodSurfaceAbort(2, $label.' is not valid JSON: '.$path);
    }

    return $decoded;
}

/**
 * The package names a requirement map points at.
 *
 * Platform requirements (`php`, `ext-*`, `lib-*`, `composer-*`) have no
 * composer.lock entry and are skipped; every other key is a package or a
 * virtual package name.
 *
 * @return list<string>
 */
function prodSurfaceRequirementNames(mixed $requirements): array
{
    if (! is_array($requirements)) {
        return [];
    }

    $names = [];

    foreach (array_keys($requirements) as $name) {
        if (! is_string($name) || $name === '') {
            continue;
        }

        if (preg_match('/^(php|hhvm|composer|composer-plugin-api|composer-runtime-api)(-|$)/i', $name) === 1) {
            continue;
        }

        if (preg_match('/^(ext|lib)-/i', $name) === 1) {
            continue;
        }

        $names[] = strtolower($name);
    }

    return $names;
}

/**
 * Index a lock section by package name, verifying the shape as it goes.
 *
 * @param  array<mixed>  $section
 * @return array<string, array<string, mixed>>
 */
function prodSurfaceIndexPackages(array $section, string $label): array
{
    $indexed = [];

    foreach ($section as $package) {
        if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === '') {
            prodSurfaceAbort(2, $label.' contains an entry without a package name — the lock is malformed.');
        }

        $indexed[strtolower($package['name'])] = $package;
    }

    return $indexed;
}

/**
 * Every package name that satisfies a requirement: the package itself, plus
 * anything it `provide`s or `replace`s.
 *
 * @param  array<string, array<string, mixed>>  $packages
 * @return array<string, string> virtual name => provider package name
 */
function prodSurfaceProviders(array $packages): array
{
    $providers = [];

    foreach ($packages as $name => $package) {
        $providers[$name] = $name;

        foreach (['provide', 'replace'] as $key) {
            foreach (array_keys(is_array($package[$key] ?? null) ? $package[$key] : []) as $provided) {
                if (is_string($provided) && $provided !== '') {
                    $providers[strtolower($provided)] ??= $name;
                }
            }
        }
    }

    return $providers;
}

$composer = prodSurfaceReadJson($composerPath, 'composer.json');
$lock = prodSurfaceReadJson($lockPath, 'composer.lock');

if (! is_array($lock['packages'] ?? null)) {
    prodSurfaceAbort(2, 'composer.lock has no `packages` list — the lock is malformed.');
}

if (! is_array($lock['packages-dev'] ?? null)) {
    prodSurfaceAbort(2, 'composer.lock has no `packages-dev` list — the lock is malformed.');
}

$require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
$requireDev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
$production = prodSurfaceIndexPackages($lock['packages'], 'composer.lock → packages');
$development = prodSurfaceIndexPackages($lock['packages-dev'], 'composer.lock → packages-dev');
$providers = prodSurfaceProviders($production + $development);

// ---- Check A: policy. ----

$violations = [];

foreach (DEV_ONLY_PACKAGES as $package) {
    if (array_key_exists($package, $require)) {
        $violations[] = $package.' is a production requirement in composer.json (require)';
    }

    if (array_key_exists($package, $production)) {
        $violations[] = $package.' is in composer.lock → packages (the --no-dev install set)';
    }

    if (array_key_exists($package, $development)) {
        continue;
    }

    if (! array_key_exists($package, $require) && ! array_key_exists($package, $development)) {
        fwrite(STDERR, '::warning::'.$package.' is no longer required at all — update DEV_ONLY_PACKAGES in '.basename(__FILE__).' if that is intended.'.PHP_EOL);
    }
}

// ---- Check B: production packages must be reachable from the require roots. ----

$queue = [];

foreach (prodSurfaceRequirementNames($require) as $name) {
    $queue[] = $name;
}

$seen = [];
$reachable = [];

while ($queue !== []) {
    $required = array_pop($queue);

    $packageName = $providers[$required] ?? $required;

    if (isset($seen[$packageName])) {
        continue;
    }

    $seen[$packageName] = true;

    // A root that is not in the lock is a platform/virtual name; nothing to
    // walk. A required package missing from the lock is a different defect
    // (`composer validate --check-lock` reports it), not this guard's job.
    if (! array_key_exists($packageName, $production) && ! array_key_exists($packageName, $development)) {
        continue;
    }

    if (array_key_exists($packageName, $production)) {
        $reachable[$packageName] = true;
    }

    $package = $production[$packageName] ?? $development[$packageName];

    foreach (prodSurfaceRequirementNames($package['require'] ?? []) as $name) {
        $queue[] = $name;
    }
}

foreach (array_keys($production) as $package) {
    if (! isset($reachable[$package])) {
        $violations[] = $package.' is in composer.lock → packages but no production requirement reaches it';
    }
}

// ---- The gate. ----

if ($violations !== []) {
    fwrite(STDERR, 'Production dependency surface is wrong (#1749):'.PHP_EOL);

    foreach ($violations as $violation) {
        fwrite(STDERR, '  - '.$violation.PHP_EOL);
    }

    fwrite(STDERR, PHP_EOL.'Developer-only tooling belongs in `require-dev`. Move the package in composer.json, then regenerate the lock with a partial update (not `composer update --lock`, which leaves the package in `packages`):'.PHP_EOL);
    fwrite(STDERR, '  composer update laravel/boost laravel/tinker --with laravel/boost:2.10.4 --with laravel/tinker:3.2.0 --no-install --minimal-changes'.PHP_EOL);

    exit(1);
}

echo 'OK — no developer-only package is in the production dependency surface ('.count($production).' production package(s) all reachable from composer.json require).'.PHP_EOL;

exit(0);
