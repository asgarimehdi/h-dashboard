<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Issue #1221 — every page that hosts the shared `<livewire:maps.map />`
 * component must give it a STABLE, literal `wire:key`.
 *
 * Why: the host page binds its layers once, in `@script`/`onReady`, and those
 * closures keep the Leaflet instance they were handed. When a structural morph
 * replaces the map subtree with a fresh `wire:id`, Alpine runs destroy/init on
 * the child, the store's identity check fails, the old instance is `remove()`d
 * and a new one is built — while the host closures stay bound to the dead one.
 * The first `addTo` then throws `appendChild`, followed by a flood of
 * `reading 'min'`, and no geometry is ever rendered.
 *
 * A literal `wire:key` keeps the element (and its `wire:id`) across the morph,
 * so the child is never re-mounted in the first place. A key on the component's
 * own root (`maps/map.blade.php`) was measured NOT to help — it has to sit on
 * the host tag, which is exactly what this guard scans.
 *
 * The host list is derived from the views, the way PageScriptSplittingTest
 * derives its Leaflet consumer list: a new page cannot silently regress by
 * forgetting the key, and a page that drops the component is simply no longer a
 * host.
 */
class MapHostWireKeyTest extends TestCase
{
    public function test_every_map_host_gives_the_component_a_stable_wire_key(): void
    {
        $hosts = $this->mapHosts();

        $this->assertNotEmpty(
            $hosts,
            'No <livewire:maps.map> host found under resources/views — the guard would pass vacuously.',
        );

        $offenders = [];

        foreach ($hosts as $path => $tags) {
            foreach ($tags as $tag) {
                if (! preg_match('/\swire:key="([^"]*)"/', $tag, $matches)) {
                    $offenders[] = "{$path}: {$tag}";

                    continue;
                }

                $key = $matches[1];

                // A dynamic key defeats the whole purpose: it changes when the
                // host re-renders, which is precisely when the child gets
                // re-mounted.
                if (! preg_match('/^[a-z0-9-]+$/', $key)) {
                    $offenders[] = "{$path}: wire:key=\"{$key}\" must be a literal, kebab-case value";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Every <livewire:maps.map> host needs a stable wire:key (issue #1221):\n"
                .implode("\n", $offenders),
        );
    }

    public function test_map_host_keys_are_unique_across_pages(): void
    {
        $keys = [];

        foreach ($this->mapHosts() as $path => $tags) {
            foreach ($tags as $tag) {
                if (preg_match('/\swire:key="([^"]*)"/', $tag, $matches)) {
                    $keys[$matches[1]][] = $path;
                }
            }
        }

        $duplicates = array_filter($keys, fn (array $paths): bool => count($paths) > 1);

        $this->assertSame(
            [],
            array_map(
                fn (string $key, array $paths): string => "wire:key=\"{$key}\" is used by ".implode(', ', $paths),
                array_keys($duplicates),
                array_values($duplicates),
            ),
            'Each map host needs its own wire:key — a shared key would make Livewire reuse one page\'s map element on another.',
        );
    }

    /**
     * Every `<livewire:maps.map ...>` opening tag, keyed by the view that holds
     * it, so a failed assertion names the file the reader has to edit.
     *
     * @return array<string, array<int, string>>
     */
    private function mapHosts(): array
    {
        $hosts = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            // Blade comments are stripped first: a documented example is not a
            // rendered host, the same rule PageScriptSplittingTest applies to
            // commented-out <script src> tags.
            $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents()) ?? $file->getContents();

            preg_match_all('/<livewire:maps\.map\b[^>]*>/', $contents, $matches);

            if ($matches[0] === []) {
                continue;
            }

            $hosts[$this->relativePath($file->getPathname())] = $matches[0];
        }

        return $hosts;
    }

    private function relativePath(string $path): string
    {
        return str_replace(resource_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
