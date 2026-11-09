<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1306 — the fail-open scope guard that keeps #819/#839 from silently returning.
 *
 * An empty unit scope is "in scope of nothing", never "unrestricted". Every one
 * of the spellings below drops the predicate when the scope list is `[]`, so a
 * user with no `user_units` row sees the whole organization. The guard is a
 * static scan of the two trees that hold query code; it never touches the DB.
 *
 * Comments are stripped before matching: the spellings are quoted verbatim in
 * several docblocks and comments (they are the documentation of the rule), and
 * a guard that flags its own documentation goes red the first time someone
 * touches those files.
 */
final class ScopeFailOpenGuardTest extends TestCase
{
    /**
     * A scope list: `$ids`, or camelCase ending in `Ids` (optionally a method
     * call), with an optional `$this->` prefix. Singular (`$unitId`) and
     * snake_case (`$this->role_ids`) are user filters, not scope lists.
     */
    private const SCOPE_VAR = '\$(?:this->)?(?:ids|[A-Za-z_][A-Za-z0-9_]*Ids)(?:\(\s*\))?';

    /**
     * Pattern groups, one per fail-open spelling. See docs/agents/access-control.md.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        // 1 — `->when($accessibleIds, …)`: an empty array is falsy, callback skipped.
        'when' => '/->when\(\s*'.self::SCOPE_VAR.'\s*,/',
        // 4 — `->when(! empty($ids), …)`: the extra guard is the same trap.
        'when-empty' => '/->when\(\s*!\s*empty\(\s*'.self::SCOPE_VAR.'\s*\)/',
        // 2 — `if (! empty($ids)) { $q->whereIn(…); }`: predicate never added.
        'empty-wrapper' => '/!\s*empty\(\s*'.self::SCOPE_VAR.'\s*\)\s*\)/',
        // 3 — `! empty($ids) && ! in_array($id, $ids)`: short-circuits and accepts.
        'empty-and-in-array' => '/!\s*empty\(\s*(?<v>'.self::SCOPE_VAR.')\s*\)\s*&&\s*!\s*in_array\([^,]*,\s*\k<v>/',
    ];

    /**
     * Persian label per group for the failure message.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'when' => 'املای ۱ — ->when($scope, …) روی دامنهٔ خالی پیش‌وند را حذف می‌کند',
        'when-empty' => 'املای ۴ — ->when(! empty($scope), …) همان دام را با ظاهرِ امن تکرار می‌کند',
        'empty-wrapper' => 'املای ۲ — if (! empty($scope)) پیش‌وند را اصلاً اضافه نمی‌کند',
        'empty-and-in-array' => 'املای ۳ — ! empty($scope) && ! in_array(…) اتصال کوتاه می‌کند و ردیف را می‌پذیرد',
    ];

    /**
     * Legitimate uses the scan must not fail on: file path, the groups it may
     * match, and a reason. Empty on day one — every live spelling is dead.
     *
     * @var array<string, array{groups: array<int, string>, reason: string}>
     */
    private const ALLOWLIST = [];

    /**
     * Files that must be in the scanned set. A typo in a root or the extension
     * filter would otherwise leave the guard green forever.
     *
     * @var array<int, string>
     */
    private const KNOWN_FILES = [
        'app/Http/Controllers/Api/UnitsExportController.php',
        'resources/views/livewire/maintenance/index.blade.php',
        'resources/views/livewire/tickets/⚡monitoring.blade.php',
    ];

    /** Today 214 files; a floor catches an accidentally narrowed scan. */
    private const MINIMUM_FILES = 150;

    public function test_scan_roots_cover_both_trees(): void
    {
        $files = $this->scannedFiles();

        foreach (self::KNOWN_FILES as $known) {
            expect($files)->toContain($known);
        }

        expect(count($files))->toBeGreaterThanOrEqual(self::MINIMUM_FILES);
    }

    public function test_comment_stripping_keeps_line_numbers_and_hides_mentions(): void
    {
        $source = "<?php\n"
            ."/**\n"
            ." * Never guard it with\n"
            ." * ->when(\$ids, fn (\$q) => \$q->whereIn('id', \$ids))\n"
            ." */\n"
            ."// never `! empty(\$ids)` either\n"
            ."{{-- nor ->when(\$ids, …) in a Blade comment --}}\n"
            ."if (! empty(\$ids)) { \$query->whereIn('unit_id', \$ids); }\n";

        $stripped = $this->stripComments($source);

        expect(strlen($stripped))->toBe(strlen($source))
            ->and(substr_count($stripped, "\n"))->toBe(substr_count($source, "\n"));

        $matches = $this->matchesInText($stripped, $source);

        expect($matches)->toHaveCount(1)
            ->and($matches[0]['group'])->toBe('empty-wrapper')
            ->and($matches[0]['line'])->toBe(8);
    }

    /**
     * All five places that quote a spelling in prose (#1306 gate C1). If any of
     * them stops being stripped, the guard goes red on documentation, not code.
     *
     * @return array<string, array{0: string}>
     */
    public static function textualMentionSamples(): array
    {
        return [
            'AccessibleTodo docblock' => ["<?php\n/**\n * Never guard it with\n * `->when(\$ids, fn (\$q) => \$q->whereIn(...))` — an empty array is falsy.\n */"],
            'PersonImport comment' => ["<?php\n// construction. Never re-add `if (! empty(\$this->accessibleUnitIds))`."],
            'TicketCommentController docblock' => ["<?php\n/**\n * never `when(\$ids, …)` or\n * `! empty(\$ids)`: `accessibleUnitIds()` is legitimately `[]`.\n */"],
            'users/index docblock' => ["<?php\n/**\n * there is no\n * `when(\$ids, …)` / `! empty(\$ids)` guard anywhere in this file.\n */"],
            'monitoring comment' => ["<?php\n// never `when(\$ids, …)`\n// or `! empty(\$ids)`: the fail-open spellings behind #813 /"],
        ];
    }

    #[DataProvider('textualMentionSamples')]
    public function test_textual_mentions_are_stripped(string $snippet): void
    {
        $stripped = $this->stripComments($snippet);

        expect($stripped)->not->toBe($snippet, "این fixture کامنت نیست، پس چیزی را ثابت نمی‌کند: {$snippet}");

        expect($this->matchesInText($stripped, $snippet))->toBe(
            [],
            "اشارهٔ متنی بعد از stripping هنوز مچ می‌شود: {$snippet}",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function positiveSamples(): array
    {
        return [
            'spelling 1' => ['when', "->when(\$accessibleIds, fn (\$q) => \$q->whereIn('id', \$accessibleIds))"],
            'spelling 4' => ['when-empty', "->when(! empty(\$accessibleIds), fn (\$q) => \$q->whereIn('id', \$accessibleIds))"],
            'spelling 2' => ['empty-wrapper', "if (! empty(\$ids)) { \$query->whereIn('unit_id', \$ids); }"],
            'spelling 3' => ['empty-and-in-array', 'if (! empty($this->accessibleUnitIds) && ! in_array($unit->id, $this->accessibleUnitIds)) { return; }'],
        ];
    }

    #[DataProvider('positiveSamples')]
    public function test_each_spelling_matches_its_positive_sample(string $group, string $sample): void
    {
        expect(preg_match(self::GROUPS[$group], $sample))->toBe(
            1,
            "گروه «{$group}» نمونهٔ مثبت خودش را مچ نمی‌کند — گارد بی‌صدا شکسته است: {$sample}",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failClosedShapes(): array
    {
        return [
            'two-when comparative' => ["->when(\$accessibleIds === [], fn (\$q) => \$q->whereRaw('1 = 0'))\n->when(\$accessibleIds !== [], fn (\$q) => \$q->whereIn('u_id', \$accessibleIds))"],
            'empty array short circuit' => ["if (\$ids === []) {\n    \$q->whereRaw('0 = 1');\n    return;\n}\n\$q->whereNull('unit_id')->orWhereIn('unit_id', \$ids);"],
            'early return on empty scope' => ["if (empty(\$accessibleIds)) {\n    return collect();\n}"],
            'unconditional in_array' => ["if (! in_array(\$unit->id, \$this->accessibleUnitIds)) {\n    return;\n}"],
            'emptiness on another variable' => ["if (! empty(\$validated['parent_id']) && ! in_array(\$validated['parent_id'], \$accessibleIds)) {\n    return response()->json([], 403);\n}"],
            'user filter singular' => ["->when(\$unitId, fn (\$q) => \$q->whereIn('u_id', \$descendantIds))"],
            'user filter snake_case' => ["if (! empty(\$this->role_ids) || ! empty(\$this->user_permissions)) {\n    \$this->authorize('manage_roles');\n}"],
        ];
    }

    #[DataProvider('failClosedShapes')]
    public function test_fail_closed_shapes_do_not_match(string $sample): void
    {
        foreach (self::GROUPS as $group => $pattern) {
            expect(preg_match($pattern, $sample))->toBe(
                0,
                "شکلِ امنِ «{$group}» به‌اشتباه مچ شد — الگو را شل نکنید: {$sample}",
            );
        }
    }

    public function test_no_fail_open_scope_spelling_in_app_or_livewire(): void
    {
        $offenders = [];

        foreach ($this->scannedFiles() as $path) {
            $allowed = self::ALLOWLIST[$path]['groups'] ?? [];

            foreach ($this->matchesInFile($path) as $match) {
                if (in_array($match['group'], $allowed, true)) {
                    continue;
                }

                $offenders[] = "{$path}:{$match['line']} → {$this->label($match['group'])}\n{$match['code']}";
            }
        }

        expect($offenders)->toBe([], implode("\n", array_merge(
            ['مصرف تازهٔ املای fail-open در scope — دامنهٔ خالی «هیچ‌چیز» است، نه «بی‌قید»:'],
            $offenders,
            [
                'جایگزین: ->whereIn($col, $ids) یا ->accessible($col)؛',
                "برای «صفر ردیف»: if (\$ids === []) { \$query->whereRaw('0 = 1'); }",
                'اگر واقعاً استثناست، با دلیل به ALLOWLIST اضافه کنید.',
            ],
        )));
    }

    public function test_every_allowlist_entry_still_matches_its_file(): void
    {
        $stale = [];

        foreach (self::ALLOWLIST as $path => $entry) {
            if (! File::exists(base_path($path))) {
                $stale[] = "{$path} → فایل وجود ندارد";

                continue;
            }

            $groups = array_column($this->matchesInFile($path), 'group');

            foreach ($entry['groups'] as $group) {
                if (! in_array($group, $groups, true)) {
                    $stale[] = "{$path} → {$this->label($group)}";
                }
            }
        }

        expect($stale)->toBe([], "مدخلِ بی‌استفاده در ALLOWLIST؛ حذفش کن:\n".implode("\n", $stale));
    }

    /**
     * @return array<int, string>
     */
    private function scannedFiles(): array
    {
        $paths = [];

        foreach ([base_path('app'), resource_path('views/livewire')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }

        return $paths;
    }

    /**
     * @return array<int, array{group: string, line: int, code: string}>
     */
    private function matchesInFile(string $path): array
    {
        $code = File::get(base_path($path));

        return $this->matchesInText($this->stripComments($code), $code);
    }

    /**
     * Matches the stripped code but reports lines from the original: stripping
     * preserves byte length and newlines, so offsets are identical.
     *
     * @return array<int, array{group: string, line: int, code: string}>
     */
    private function matchesInText(string $stripped, string $original): array
    {
        $lines = preg_split('/\R/', $original) ?: [];
        $found = [];

        foreach (self::GROUPS as $group => $pattern) {
            preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE);

            /** @var array<int, array{0: string, 1: int}> $hits */
            $hits = $matches[0];

            foreach ($hits as [, $offset]) {
                $line = substr_count(substr($original, 0, $offset), "\n") + 1;

                $found[] = [
                    'group' => $group,
                    'line' => $line,
                    'code' => trim($lines[$line - 1] ?? ''),
                ];
            }
        }

        return $found;
    }

    /**
     * Blank Blade comments first (the tokenizer sees them as plain text), then
     * blank PHP comments and docblocks. Newlines survive, so line numbers hold.
     */
    private function stripComments(string $code): string
    {
        $code = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn (array $match): string => $this->blank($match[0]),
            $code,
        ) ?? $code;

        $stripped = '';

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                $stripped .= $token;

                continue;
            }

            [$id, $text] = $token;

            $stripped .= in_array($id, [T_COMMENT, T_DOC_COMMENT], true) ? $this->blank($text) : $text;
        }

        return $stripped;
    }

    private function blank(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? $text;
    }

    private function label(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }
}
