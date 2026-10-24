<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Issue #1063 — `axios` was bundled into the global layout to serve exactly one
 * GET (the geocoding call in `maps/route2.blade.php`). It is replaced by the
 * native `fetch`, which removes a globally-loaded HTTP dependency (axios has
 * the longest client-side HTTP CVE history of any such library) from every page.
 *
 * These are source-reading guards, the pattern already used by
 * `PageScriptSplittingTest` and `HardwareExportNormalizationTest`. They pin the
 * three behaviours the swap can silently break — the query-string encoding, a
 * non-array response, and a non-2xx HTTP status — because the repo has no JS
 * test runner, and this PR's whole point is to not add a dependency.
 *
 * The one legitimate `axios` left under `resources/` is
 * `views/op/index.php`: a local-only OPcache debug tool behind `isLocal()` that
 * loads axios from its own CDN, never from the bundle. The allowlist is asserted
 * rather than assumed, so it cannot silently become a real consumer.
 */
class GeocodingFetchMigrationTest extends TestCase
{
    private const AXIOS_CDN_EXCEPTION = 'resources/views/op/index.php';

    /**
     * A real consumer — an axios call, import or CDN tag — not a prose mention.
     * The migration comments deliberately name axios, and a guard that trips on
     * documentation would be deleted by the next person who touches the file.
     */
    private const AXIOS_USAGE = '/(\baxios\s*\.)|(\bfrom\s+[\'"]axios[\'"])|(require\(\s*[\'"]axios[\'"])|(window\.axios)|(<script[^>]*axios)/';

    public function test_bootstrap_js_no_longer_ships_axios(): void
    {
        $bootstrap = (string) file_get_contents(resource_path('js/bootstrap.js'));

        expect($bootstrap)->not->toMatch('/\bimport\s+axios\b/');
        expect($bootstrap)->not->toMatch('/window\.axios\s*=/');
        expect($bootstrap)->not->toContain('X-Requested-With');

        // …while the map store registration that shares the file must survive.
        expect($bootstrap)->toContain('registerMapStore');
        expect($bootstrap)->toContain("addEventListener('alpine:init'");
    }

    public function test_axios_is_not_a_node_dependency(): void
    {
        $package = json_decode(
            (string) file_get_contents(base_path('package.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($package['devDependencies'])->not->toHaveKey('axios');
        expect($package['dependencies'] ?? [])->not->toHaveKey('axios');
    }

    public function test_no_bundled_axios_consumer_is_left_under_resources(): void
    {
        $consumers = [];

        foreach ($this->resourceFiles() as $relative => $contents) {
            if ($relative === self::AXIOS_CDN_EXCEPTION) {
                continue;
            }

            if (preg_match(self::AXIOS_USAGE, $contents) === 1) {
                $consumers[] = $relative;
            }
        }

        expect($consumers)->toBe([], 'every axios consumer must be migrated to fetch');

        // The allowlist stays honest: its axios really comes from a CDN, so it
        // never depends on the npm package this PR removes.
        $exception = (string) file_get_contents(base_path(self::AXIOS_CDN_EXCEPTION));
        expect($exception)->toContain('cdnjs.cloudflare.com/ajax/libs/axios/');
    }

    public function test_geocoding_call_uses_fetch_with_encoding_and_response_guards(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/maps/route2.blade.php'));

        expect($view)->not->toMatch(self::AXIOS_USAGE);
        expect($view)->toContain('fetch(');

        // Manual query strings are where a library swap breaks silently: keep
        // the encoding delegated to URLSearchParams.
        expect($view)->toContain('URLSearchParams');
        expect($view)->toContain("set('q'");
        expect($view)->toContain("set('format', 'json')");
        expect($view)->toContain("set('limit', '1')");

        // fetch does not reject on 4xx/5xx, and a JSON error body is not an
        // array — both must be handled instead of throwing into the catch.
        expect($view)->toContain('response.ok');
        expect($view)->toContain('Array.isArray');
    }

    /**
     * Every JS and Blade/PHP file shipped from `resources/`, keyed by its path
     * relative to the repository root.
     *
     * @return array<string, string>
     */
    private function resourceFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path()),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $name = $file->getFilename();

            if (! preg_match('/\.(js|ts|php)$/', $name)) {
                continue;
            }

            $relative = 'resources/'.ltrim(
                substr($file->getPathname(), strlen(resource_path())),
                DIRECTORY_SEPARATOR,
            );

            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }
}
