<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1308 — the public-disk guard that keeps #1276 from silently returning.
 *
 * #1276 moved ticket attachments to the private `local` disk and served them
 * through an authenticated, unit-scoped route. Nothing stops the next ticket
 * screen from calling `->store('attachments', 'public')` again, or from
 * building a `/storage/...` link next to a file that lives on `local` — the
 * exact shape of the #880/#1276 exposure.
 *
 * Static scan, no database: the guard reads app/ and resources/views/ only.
 * Widening the scope to tests/ or config/ would flag the documented fixtures
 * and the public disk definition itself, so do not widen it.
 */
final class NoPublicDiskStorageTest extends TestCase
{
    /**
     * Pattern groups. Each one names a different way to reach the public disk,
     * so a failure can say which way the new code took.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        // Writing a file: `$file->store('attachments', 'public')`, `->storeAs(...)`.
        'write' => '/->(?:store|storeAs)\s*\([^;]*?[\'"]public[\'"]/s',
        // Other write shapes. On beta only the VendorRouteGuard docblock matches.
        'write-other' => '/->(?:put|putFile|writeStream)\s*\([^;]*?[\'"]public[\'"]/s',
        // Taking the public disk handle, the other road to writing or serving.
        'handle' => '/Storage::disk\(\s*[\'"]public[\'"]\s*\)/',
        // Serving a public URL — a local file linked from /storage leaks just as well.
        'serve' => '/Storage::url\(|asset\(\s*[\'"]storage/',
    ];

    /**
     * Persian label per group for the failure message.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'write' => 'نوشتن روی دیسک public (store/storeAs)',
        'write-other' => 'نوشتن روی دیسک public (put/putFile/writeStream)',
        'handle' => 'گرفتن هندل Storage::disk(\'public\')',
        'serve' => 'سرو لینک public (Storage::url / asset(\'storage…\'))',
    ];

    /**
     * Legitimate uses the scan must not fail on. Each entry is a file path plus
     * the groups it is allowed to match, and a reason. No line numbers: they
     * move with every edit and would keep a dead entry looking "necessary".
     *
     * @var array<string, array{groups: array<int, string>, reason: string}>
     */
    private const ALLOWLIST = [
        'app/Console/Commands/MoveAttachmentsToLocal.php' => [
            'groups' => ['handle'],
            'reason' => 'فرمان یک‌بارمصرفِ مهاجرتِ #1276 است و باید فایل‌های legacy را از همان دیسک public بخواند.',
        ],
        'app/Support/VendorRouteGuard.php' => [
            'groups' => ['write-other'],
            'reason' => 'فقط PHPDoc است و امضای آپلودِ vendor مِری را نقل می‌کند؛ رفتار واقعی در MaryVendorUploadRouteTest پین شده است.',
        ],
    ];

    /**
     * Positive literals so a broken regex cannot leave the guard silently green.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function positiveSamples(): array
    {
        return [
            'store' => ['write', "->store('a', 'public')"],
            'storeAs' => ['write', "->storeAs('a', 'n', 'public')"],
            'put' => ['write-other', "->put('a', 'b', 'public')"],
            'putFile' => ['write-other', "->putFile('a', 'b', 'public')"],
            'writeStream' => ['write-other', "->writeStream('a', 'b', 'public')"],
            'disk handle' => ['handle', "Storage::disk('public')"],
            'storage url' => ['serve', "Storage::url('x')"],
            'asset storage' => ['serve', "asset('storage/x.pdf')"],
        ];
    }

    #[DataProvider('positiveSamples')]
    public function test_each_pattern_group_matches_its_positive_sample(string $group, string $sample): void
    {
        expect(preg_match(self::GROUPS[$group], $sample))->toBe(
            1,
            "گروه «{$group}» نمونهٔ مثبت خودش را مچ نمی‌کند — گارد بی‌صدا شکسته است: {$sample}",
        );
    }

    public function test_no_code_writes_to_or_serves_the_public_disk(): void
    {
        $offenders = [];

        foreach ($this->scannedFiles() as $path) {
            $allowed = self::ALLOWLIST[$path]['groups'] ?? [];

            foreach ($this->matchesInFile($path) as $match) {
                if (in_array($match['group'], $allowed, true)) {
                    continue;
                }

                $offenders[] = "{$path}:{$match['line']} → {$this->label($match['group'])}";
            }
        }

        expect($offenders)->toBe([], implode("\n", array_merge(
            ['مصرف تازهٔ دیسک public پیدا شد — همان شکل افشای #880/#1276:'],
            $offenders,
            ['فایل را روی دیسک local بنویسید و لینکش را از مسیر احرازشده بدهید؛ اگر واقعاً legacy است، با دلیل به ALLOWLIST اضافه کنید.'],
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

        foreach ([base_path('app'), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $paths[] = $this->relativePath($file->getPathname());
            }
        }

        return $paths;
    }

    /**
     * @return array<int, array{group: string, line: int}>
     */
    private function matchesInFile(string $path): array
    {
        $code = File::get(base_path($path));
        $found = [];

        foreach (self::GROUPS as $group => $pattern) {
            preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);

            /** @var array<int, array{0: string, 1: int}> $hits */
            $hits = $matches[0];

            foreach ($hits as [, $offset]) {
                $found[] = [
                    'group' => $group,
                    'line' => substr_count(substr($code, 0, $offset), "\n") + 1,
                ];
            }
        }

        return $found;
    }

    private function relativePath(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(base_path()) + 1));
    }

    private function label(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }
}
