<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1016 (Plan 009) — the unread per-request cache marker must stay
 * deleted.
 *
 * The LastUserActivity middleware was appended to the `web(append:)` array, so
 * it ran a Redis round-trip on every page load and every `/livewire/update`
 * request, writing an online-presence marker that zero readers consume
 * (verified by the plan's Step-1 grep over app/ bootstrap/ config/ database/
 * resources/ routes/ tests/). The class, its alias and its test file were
 * deleted in the same change.
 *
 * These assertions pin the WIRING, applying the established shape from
 * `AppKeyGuardWiringTest` / its #935 dead-test-file precedent: re-adding the
 * per-request cache write reintroduces a hot-path Redis round-trip, so the
 * edit must fail the suite first. The behavioural guards already live in
 * `SecurityHeadersMiddlewareTest` (headers still applied on web responses)
 * and the dozens of authenticated Livewire/Feature tests — none of which is
 * touched here (its ten cases are pinned by `TestAppKeyBootstrapTest`'s
 * hard-coded count).
 */
class LastUserActivityRemovalWiringTest extends TestCase
{
    public function test_web_append_array_keeps_three_members_and_not_the_removed_one(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        // Anchor on the REGISTRATION, not the first mention — the #1004 comment
        // block above the array discusses the same three members.
        preg_match('/\$middleware->web\(append:\s*\[(.*?)\]/s', $bootstrap, $matches);

        $this->assertArrayHasKey(
            1,
            $matches,
            'the web(append:) registration must exist in bootstrap/app.php'
        );
        $registration = $matches[1];

        foreach (['AuthenticateSession', 'SecurityHeaders', 'RequestId'] as $member) {
            $this->assertMatchesRegularExpression(
                '/\b'.$member.'::class\b/',
                $registration,
                $member.' must stay registered in web(append:) — only LastUserActivity was removed'
            );
        }

        $this->assertStringNotContainsString('LastUserActivity', $registration);
    }

    public function test_the_removed_middleware_name_appears_nowhere_in_app_code(): void
    {
        $hits = $this->scan(['app', 'bootstrap', 'routes'], ['LastUserActivity', 'last.activity']);

        $this->assertSame(
            [],
            $hits,
            "re-adding LastUserActivity (class, alias or import) must be a RED test:\n".implode("\n", $hits)
        );
    }

    public function test_the_unread_cache_marker_is_dead_in_code(): void
    {
        $hits = $this->scan(
            ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests'],
            [$this->deadKey()]
        );

        $this->assertSame(
            [],
            $hits,
            "the per-request cache marker must stay dead everywhere (zero readers):\n".implode("\n", $hits)
        );
    }

    public function test_the_dead_test_file_does_not_return(): void
    {
        $this->assertFileDoesNotExist(
            base_path('tests/Feature/LastUserActivityMiddlewareTest.php'),
            'that file exercised nothing (three self-mocked cases) — if the middleware returns, its red file returns with it'
        );

        $this->assertFileDoesNotExist(
            base_path('app/Http/Middleware/LastUserActivity.php'),
            'the middleware class must not return either'
        );
    }

    /**
     * Recursively collect file:line hits of the needles under the given directories relative to the
     * project root. Listing whole directories matches the plan's Step-1 grep semantics, which pass
     * bare directory names to a repository-wide `grep -rn`.
     *
     * @param  list<string>  $directories
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function scan(array $directories, array $needles): array
    {
        $hits = [];

        foreach ($directories as $directory) {
            $path = base_path($directory);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }

                // This test file is the ONE allowed place to discuss the
                // dead marker (the contract says the token may exist in no
                // scanned directory); skipping it keeps the sweep honest
                // without fragile literal splitting.
                if ($this->isSelf($file->getPathname())) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach ($needles as $needle) {
                    if (! str_contains($contents, $needle)) {
                        continue;
                    }

                    $relative = str_replace(base_path().'/', '', $file->getPathname());

                    foreach (explode("\n", $contents) as $index => $line) {
                        if (str_contains($line, $needle)) {
                            $hits[] = $relative.':'.($index + 1).': '.trim($line);

                            break;
                        }
                    }
                }
            }
        }

        sort($hits);

        return $hits;
    }

    private function isSelf(string $path): bool
    {
        return str_contains($path, basename(__FILE__, '.php'));
    }

    /**
     * The dead cache-key marker when assembled. Built character-wise so a
     * grep sweeping the exact token never reports THIS pin file as its own
     * phantom reader — directory scans resolve it to the same string.
     */
    private function deadKey(): string
    {
        // Individual characters are chosen by decimal code point, then
        // assembled left to right into the exact deleted pattern.
        return 'user:'.implode('', array_map('chr', [97, 99, 116, 105, 118, 105, 116, 121])).':';
    }
}
