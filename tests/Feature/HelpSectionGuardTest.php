<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Issue #1537 — pin every help call site to the section it actually documents.
 *
 * The modal renders all 22 content bodies into the open response (#1166), so a
 * Pest `assertSee` on the modal body cannot tell which section is active. What
 * *is* per-section is the wrapper's `data-section` (modal.blade.php:13), which
 * comes from the `section` prop of the `<x-help:modal>` call site. Before #1537
 * 24 of 25 pages omitted that prop and relied on the `help-open` event and a
 * `'dashboard'` fallback; the copy stayed right only by accident of ordering.
 *
 * This is a static scan, not a render test: rendering 25 pages for this fact
 * was rejected. `help-modal.spec.ts` test 3 stays the behavioural witness.
 *
 * Comments are stripped before matching because two views quote the tag in
 * prose (button.blade.php:11, backup/index.blade.php:40); a raw scan sees 27
 * call sites in 26 files instead of the 25 real ones.
 */
final class HelpSectionGuardTest extends TestCase
{
    private const VIEW_ROOT = 'resources/views';

    private const HELP_MODAL = 'resources/views/components/help/modal.blade.php';

    private const HELP_BUTTON = 'resources/views/components/help/button.blade.php';

    private const HELP_CONTENT_DIR = 'resources/views/components/help/content';

    private const APP_COMPONENTS = 'resources/js/app-components.js';

    private const CALL_SITE = '/<x-help:(modal|button)\b[^>]*>/';

    private const SECTION_ATTR = '/\bsection\s*=\s*["\']([^"\']*)["\']/';

    /** 29 pages carry one button + one modal; the 30th must fail loudly. */
    private const PAIRED_FILES = 29;

    /** 21 distinct button sections; `todos` has content but no live button. */
    private const BUTTON_SECTIONS = 21;

    /** The registry must hold exactly 22 sections. */
    private const CONTENT_SECTIONS = 22;

    /**
     * A1 — no `<x-help:modal>` call site may fall back to a guessed section.
     *
     * Every offender is listed in a single failure; this is one contract, not
     * 24 separate tests.
     */
    public function test_a1_every_help_modal_call_site_passes_a_section(): void
    {
        $violations = [];

        foreach ($this->viewFiles() as $path) {
            $source = $this->stripComments(File::get(base_path($path)));

            preg_match_all(self::CALL_SITE, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $index => [$tag, $offset]) {
                if ($matches[1][$index][0] !== 'modal') {
                    continue;
                }

                if (! preg_match(self::SECTION_ATTR, $tag)) {
                    $violations[] = $path.':'.$this->lineAt($source, $offset);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "هر <x-help:modal> باید section خودش را بدهد؛ بدون آن مودال به fallback می‌افتد:\n"
                .implode("\n", $violations),
        );
    }

    /**
     * A2 — each paired page has exactly one button and one modal, and the
     * modal's section is one of that page's button sections (modal ⊆ button).
     *
     * Only this direction is asserted: button ⊆ modal fails exactly where A1
     * fails, and duplicating it would only duplicate the red.
     */
    public function test_a2_help_modal_section_matches_a_button_section_on_the_same_page(): void
    {
        [$buttons, $modals] = $this->callSites();

        $files = array_values(array_unique(array_merge(array_keys($buttons), array_keys($modals))));
        sort($files);

        $problems = [];

        foreach ($files as $path) {
            $buttonSections = $buttons[$path] ?? [];
            $modalSections = $modals[$path] ?? [];

            if (count($buttonSections) !== 1) {
                $problems[] = $path.' — '.count($buttonSections).' دکمه (باید ۱ باشد)';
            }

            if (count($modalSections) !== 1) {
                $problems[] = $path.' — '.count($modalSections).' مودال (باید ۱ باشد)';
            }

            if (count($buttonSections) !== 1 || count($modalSections) !== 1) {
                continue;
            }

            $modalSection = $modalSections[0];

            if ($modalSection === null) {
                $problems[] = $path.' — مودال section نمی‌دهد';

                continue;
            }

            if (! in_array($modalSection, $buttonSections, true)) {
                $problems[] = $path.' — section مودال «'.$modalSection
                    .'» در sectionهای دکمه نیست ['.implode(', ', $buttonSections).']';
            }
        }

        $this->assertCount(
            self::PAIRED_FILES,
            $files,
            'صفحه‌های دارای دکمه/مودال راهنما باید دقیقاً '.self::PAIRED_FILES.' باشند.',
        );

        $this->assertSame([], $problems, "section مودال باید زیرمجموعهٔ section دکمهٔ همان فایل باشد:\n"
            .implode("\n", $problems));
    }

    /**
     * A3 — the button set and the modal set are the same 29 files.
     */
    public function test_a3_button_and_modal_file_sets_are_identical(): void
    {
        [$buttons, $modals] = $this->callSites();

        $buttonFiles = array_keys($buttons);
        $modalFiles = array_keys($modals);
        sort($buttonFiles);
        sort($modalFiles);

        $this->assertSame(
            $buttonFiles,
            $modalFiles,
            'هر صفحه‌ای که دکمهٔ راهنما دارد باید همان مودال را هم داشته باشد و برعکس.',
        );

        $this->assertCount(self::PAIRED_FILES, $buttonFiles);
    }

    /**
     * A4 — the 21 distinct button sections all have a content file.
     */
    public function test_a4_every_button_section_has_a_content_file(): void
    {
        [, , $buttonSections] = $this->callSites();

        $distinct = array_values(array_unique(array_filter($buttonSections, fn ($s) => $s !== null)));
        sort($distinct);

        $this->assertCount(self::BUTTON_SECTIONS, $distinct, 'sectionهای متمایز دکمه‌ها تغییر کرده‌اند.');

        $missing = array_values(array_diff($distinct, $this->contentSections()));

        $this->assertSame(
            [],
            $missing,
            'این sectionهای دکمه فایل محتوا ندارند: '.implode(', ', $missing),
        );
    }

    /**
     * A5 — the single registry agrees with the content directory, and both
     * readers read from it instead of keeping their own lists.
     *
     * #1535 Phase 0 deleted the three hand-maintained lists this test used to
     * pin ($helpContents, the modal x-if chain, noHelpContent()). What remains
     * is one registry, config/help.php, and two readers: the modal iterates it
     * (and hands the same list to Alpine via data-sections) and noHelpContent()
     * reads it from the dataset. A drift between the registry and the content
     * directory, or a reader that stops reading the registry, fails here.
     */
    public function test_a5_registry_and_readers_match_the_content_directory(): void
    {
        $contents = $this->contentSections();

        $this->assertCount(self::CONTENT_SECTIONS, $contents);

        $registered = config('help.sections');
        sort($registered);

        $this->assertSame(
            $contents,
            $registered,
            'config/help.php با فایل‌های محتوا یکی نیست.',
        );

        $modal = File::get(base_path(self::HELP_MODAL));

        $this->assertStringContainsString(
            "config('help.sections')",
            $modal,
            'مودال باید لیست sectionها را از config/help.php بخواند.',
        );

        $this->assertStringContainsString(
            'data-sections="{{ implode',
            $modal,
            'مودال باید همان لیست را با data-sections به Alpine بدهد.',
        );

        $this->assertStringContainsString(
            '$helpSections) }}"',
            $modal,
            'مودال باید همان لیست را با data-sections به Alpine بدهد.',
        );

        $js = File::get(base_path(self::APP_COMPONENTS));

        $this->assertMatchesRegularExpression(
            '/noHelpContent\(\)\s*\{[^}]*dataset\.sections/s',
            $js,
            'noHelpContent() باید لیست را از dataset بخواند، نه از یک لیست جدا.',
        );

        $this->assertStringNotContainsString(
            "'hr-dashboard'",
            $js,
            'app-components.js نباید لیست section جدا نگه دارد.',
        );
    }

    /**
     * A6 — the button default is the modal fallback, and that fallback is not a
     * content section. A future page that forgets `section` therefore shows the
     * explicit «راهنمای این بخش در دسترس نیست» instead of silently rendering
     * another page's help.
     */
    public function test_a6_button_default_equals_modal_fallback_and_has_no_content(): void
    {
        $buttonDefault = $this->capture(
            self::HELP_BUTTON,
            '/\'section\'\s*=>\s*\'([^\']*)\'/',
            'پیش‌فرض section در button.blade.php پیدا نشد.',
        );

        $modalFallback = $this->capture(
            self::HELP_MODAL,
            '/\$section\s*\?\?\s*\'([^\']*)\'/',
            'پیش‌فرض section در modal.blade.php پیدا نشد.',
        );

        $initFallback = $this->capture(
            self::APP_COMPONENTS,
            '/helpSection:\s*\'([^\']*)\'/',
            'مقدار اولیهٔ helpSection در app-components.js پیدا نشد.',
        );

        $datasetFallback = $this->capture(
            self::APP_COMPONENTS,
            '/dataset\.section\s*\|\|\s*\'([^\']*)\'/',
            'fallback داده‌ی dataset.section در app-components.js پیدا نشد.',
        );

        $this->assertSame($buttonDefault, $modalFallback, 'پیش‌فرض دکمه و مودال باید یکی باشند.');
        $this->assertSame($buttonDefault, $initFallback, 'مقدار اولیهٔ Alpine باید همان پیش‌فرض دکمه باشد.');
        $this->assertSame($buttonDefault, $datasetFallback, 'fallback داده‌ی Alpine باید همان پیش‌فرض دکمه باشد.');

        $this->assertNotContains(
            $buttonDefault,
            $this->contentSections(),
            'پیش‌فرض نباید خودش یک section محتوایی باشد، وگرنه صفحهٔ بدون section راهنمای صفحهٔ دیگری را نشان می‌دهد.',
        );
    }

    /**
     * Every help button/modal call site, keyed by file. Each entry is the tag's
     * `section` attribute, or `null` when the call site omits it.
     *
     * @return array{0: array<string, array<int, string|null>>, 1: array<string, array<int, string|null>>, 2: array<int, string|null>}
     */
    private function callSites(): array
    {
        $buttons = [];
        $modals = [];
        $buttonSections = [];

        foreach ($this->viewFiles() as $path) {
            $source = $this->stripComments(File::get(base_path($path)));

            preg_match_all(self::CALL_SITE, $source, $matches);

            foreach ($matches[0] as $index => $tag) {
                $section = $this->sectionOf($tag);

                if ($matches[1][$index] === 'button') {
                    $buttons[$path][] = $section;
                    $buttonSections[] = $section;
                } else {
                    $modals[$path][] = $section;
                }
            }
        }

        return [$buttons, $modals, $buttonSections];
    }

    /**
     * Every `.blade.php` file under resources/views, relative to the repo root.
     *
     * @return array<int, string>
     */
    private function viewFiles(): array
    {
        $root = base_path(self::VIEW_ROOT);

        $files = [];

        foreach (File::allFiles($root) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $files[] = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                ltrim(str_replace(base_path(), '', $file->getPathname()), DIRECTORY_SEPARATOR),
            );
        }

        sort($files);

        return $files;
    }

    /**
     * Section names from the content directory, sorted.
     *
     * @return array<int, string>
     */
    private function contentSections(): array
    {
        $sections = [];

        foreach (File::glob(base_path(self::HELP_CONTENT_DIR).'/*.blade.php') as $path) {
            $sections[] = basename($path, '.blade.php');
        }

        sort($sections);

        return $sections;
    }

    private function sectionOf(string $tag): ?string
    {
        return preg_match(self::SECTION_ATTR, $tag, $match) ? $match[1] : null;
    }

    /**
     * Read a required scalar out of a help-system source file.
     */
    private function capture(string $path, string $pattern, string $failure): string
    {
        if (! preg_match($pattern, File::get(base_path($path)), $match)) {
            $this->fail($failure);
        }

        return $match[1];
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * Blank comments without moving lines: Blade `{{-- --}}`, `/* *\/` and
     * `//` (but not the `//` in a URL). Two views quote the help tags in prose.
     */
    private function stripComments(string $source): string
    {
        $blank = static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));

        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source) ?? $source;
        $source = preg_replace_callback('#/\*.*?\*/#s', $blank, $source) ?? $source;

        return preg_replace_callback('~(?:^|(?<!:))//[^\n]*~m', $blank, $source) ?? $source;
    }
}
