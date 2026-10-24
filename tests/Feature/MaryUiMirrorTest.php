<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two app-owned Blade components in app/View/Components/ mirror maryUI's
 * own render() so that a `<th>` and a menu `<a>` can carry attributes the
 * vendor markup has no room for (#957, step 2). A mirror is only as good as
 * its fidelity, and nothing about a maryUI upgrade forces anyone to re-read
 * them: `composer update robsontenorio/mary` silently leaves these files
 * rendering yesterday's markup against today's class hierarchy.
 *
 * This is the guard for that. It asserts each mirror equals the vendor heredoc
 * plus ONLY the accessibility lines — nothing else may drift. A vendor upgrade
 * that changes Table::render() turns this red instead of turning production
 * quietly wrong.
 */
class MaryUiMirrorTest extends TestCase
{
    /**
     * Lines a mirror is allowed to add. Anything else is drift.
     */
    private const ALLOWED_ADDITION = '/^(aria-sort|aria-current|aria-pressed|tabindex|@keydown\.)/';

    private const MIRRORS = [
        // mirror => [vendor class file, app class file]
        'table' => [
            'vendor/robsontenorio/mary/src/View/Components/Table.php',
            'app/View/Components/AccessibleTable.php',
        ],
        'menu-item' => [
            'vendor/robsontenorio/mary/src/View/Components/MenuItem.php',
            'app/View/Components/AccessibleMenuItem.php',
        ],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mirrorProvider(): array
    {
        return array_combine(
            array_keys(self::MIRRORS),
            array_values(self::MIRRORS)
        );
    }

    #[DataProvider('mirrorProvider')]
    public function test_the_mirror_adds_only_accessibility_attributes(string $vendorPath, string $mirrorPath): void
    {
        [$vendorLines, $mirrorLines] = $this->divergence(
            $this->significantLines($vendorPath),
            $this->significantLines($mirrorPath)
        );

        // A vendor line the mirror dropped is a REGRESSION, not an addition: the
        // mirror would stop rendering part of the component.
        $this->assertSame(
            [],
            $vendorLines,
            "{$mirrorPath} no longer renders lines that maryUI's component does. Re-sync it against {$vendorPath}."
        );

        $this->assertNotEmpty(
            $mirrorLines,
            "{$mirrorPath} adds nothing to {$vendorPath} — is the accessibility change still there?"
        );

        foreach ($this->classifyAdditions($mirrorLines) as [$line, $kind]) {
            $this->assertContains(
                $kind,
                ['attribute', 'guard'],
                "{$mirrorPath} diverges from {$vendorPath} in a way the accessibility change does not explain: `{$line}`. "
                .'If maryUI changed its markup, re-sync the mirror rather than widening this list.'
            );
        }
    }

    /**
     * Labels each added line 'attribute' or 'guard'.
     *
     * `aria-current` cannot be a bare attribute — it has to be conditional on
     * the same expression the active class uses, or it would fire on every
     * item. So an `@if(…)`/`@endif` pair around an added attribute is the
     * expected shape, and is allowed ONLY that shape: a guard that wraps
     * nothing, or wraps markup, is drift.
     *
     * @param  array<int, string>  $added
     * @return array<int, array{0: string, 1: string}>
     */
    private function classifyAdditions(array $added): array
    {
        $classified = [];
        $guardDepth = 0;

        foreach ($added as $line) {
            if (preg_match(self::ALLOWED_ADDITION, $line) === 1) {
                $classified[] = [$line, 'attribute'];

                continue;
            }

            if (str_starts_with($line, '@if(') && $guardDepth === 0) {
                $guardDepth = 1;
                $classified[] = [$line, 'guard'];

                continue;
            }

            if ($line === '@endif' && $guardDepth === 1) {
                $guardDepth = 0;
                $classified[] = [$line, 'guard'];

                continue;
            }

            $classified[] = [$line, 'structure'];
        }

        if ($guardDepth !== 0) {
            $classified[] = ['@endif (never closed)', 'structure'];
        }

        return $classified;
    }

    /**
     * Lines present in `$mirror` but not in `$vendor`, in order, plus lines
     * present in `$vendor` but not in `$mirror`.
     *
     * A two-pointer merge rather than array_diff: diff ignores order, so a
     * mirror that shuffled the table body would pass a set comparison and fail
     * the browser.
     *
     * @param  array<int, string>  $vendor
     * @param  array<int, string>  $mirror
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function divergence(array $vendor, array $mirror): array
    {
        $onlyVendor = [];
        $onlyMirror = [];

        $i = 0;
        $j = 0;

        while ($i < count($mirror) && $j < count($vendor)) {
            if ($mirror[$i] === $vendor[$j]) {
                $i++;
                $j++;

                continue;
            }

            $onlyMirror[] = $mirror[$i];
            $i++;
        }

        $onlyVendor = array_slice($vendor, $j);
        $onlyMirror = array_merge($onlyMirror, array_slice($mirror, $i));

        return [$onlyVendor, $onlyMirror];
    }

    /**
     * The render() heredoc of a component, one trimmed line per entry, with
     * blank lines and Blade comments removed.
     *
     * Comments go because a mirror is allowed to explain itself: stripping them
     * from both sides keeps the comparison about markup, which is what actually
     * renders. `{{-- … --}}` blocks span several lines, so the scan tracks
     * whether it is inside one.
     *
     * @return array<int, string>
     */
    private function significantLines(string $path): array
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString($source, "cannot read {$path}");

        $start = strpos($source, "<<<'BLADE'") ?: strpos($source, "<<<'HTML'");
        $this->assertNotFalse($start, "{$path} has no render() heredoc");

        $body = substr($source, $start);
        $body = preg_replace('/\n\s*(BLADE|HTML);\s*$/', '', $body);

        $lines = [];
        $inComment = false;

        foreach (explode("\n", (string) $body) as $line) {
            $line = trim($line);

            if (! $inComment && str_starts_with($line, '{{--')) {
                $inComment = ! str_contains($line, '--}}');

                continue;
            }

            if ($inComment) {
                $inComment = ! str_contains($line, '--}}');

                continue;
            }

            if ($line === '' || str_starts_with($line, '{{--')) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
