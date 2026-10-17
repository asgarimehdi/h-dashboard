<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1064 — `dotenv` was a redundant devDependency.
 *
 * Its only consumer in the build was `playwright.config.ts`, and CI runs Node 24
 * where `process.loadEnvFile()` (Node ≥ 20.12) is native. This guard keeps the
 * package from creeping back in and pins the replacement API.
 *
 * The scan is deliberately narrowed to the *dependency* and to real
 * `import`/`require` lines: `scripts/check-app-key-leak.php` and
 * `scripts/ensure-test-app-key.php` mention "dotenv" in prose comments only, so
 * a bare grep for the word would fail the moment it is run.
 */
class PlaywrightEnvLoadingTest extends TestCase
{
    public function test_dotenv_is_not_a_node_dependency(): void
    {
        $package = json_decode(
            (string) file_get_contents(base_path('package.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($package['devDependencies'])->not->toHaveKey('dotenv');
        expect($package['dependencies'] ?? [])->not->toHaveKey('dotenv');
    }

    public function test_playwright_config_loads_env_file_with_node_api(): void
    {
        $config = file_get_contents(base_path('playwright.config.ts'));

        expect($config)->not->toMatch('/\b(?:import|require)\b[^\n]*[\'"]dotenv[\'"]/');
        expect($config)->toContain('process.loadEnvFile');
    }
}
