<?php

namespace Tests\Feature;

use App\Console\Commands\PruneStaleCache;
use App\Models\ZabbixDevice;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1290 — the guard against hand-built cache version keys.
 *
 * Versioned cache keys are a contract between three places: the writer that
 * bumps `{namespace}_version`, the reader that builds `{namespace}:v{n}:…`, and
 * the `PruneStaleCache::NAMESPACES` registry the audit walks. A hand-built key
 * that skips `CacheInvalidationService` still "works" until someone renames a
 * namespace — then it silently reads a version nobody bumps, and the cache
 * becomes permanently stale with no error anywhere.
 *
 * Static scan, no database: reads app/ and resources/views/ only. Widening the
 * scope to tests/ would flag the documented fixtures, and config/ holds the
 * cache store definitions themselves.
 */
final class CacheVersionKeyGuardTest extends TestCase
{
    /**
     * The only file allowed to spell the physical version key.
     */
    private const SERVICE = 'app/Services/CacheInvalidationService.php';

    /**
     * Pattern groups. Each names a different way to bypass the service, so a
     * failure can say which way the new code took.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        // Reading/writing the raw version counter: `Cache::get('hr_stats_version')`.
        'version-literal' => '/[\'"][^\'"]*_version[\'"]/',
        // A version baked into a data key: `Cache::remember("dashboard:stats:v{$v}", …)`.
        // The first group cannot see this — the version arrives through `$v`.
        'versioned-key-literal' => '/Cache::(?:remember|get)\(\s*[\'"][^\'"]*:v/',
    ];

    /**
     * Persian label per group for the failure message.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'version-literal' => 'ساخت/خواندن دستی کلید نسخه (___version)',
        'versioned-key-literal' => 'قرار دادن نسخه داخل کلید داده (…:v…)',
    ];

    /**
     * Legitimate uses the scan must not fail on. No line numbers: they move
     * with every edit and would keep a dead entry looking "necessary".
     *
     * @var array<string, array{groups: array<int, string>, reason: string}>
     */
    private const ALLOWLIST = [
        self::SERVICE => [
            'groups' => ['version-literal'],
            'reason' => 'تنها جایی که جای‌نگهدار {ns}_version ساخته می‌شود؛ همین سرویس مالک کلید فیزیکی است.',
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
            'get version (single quotes)' => ['version-literal', "Cache::get('hr_stats_version', 0)"],
            'get version (double quotes)' => ['version-literal', 'Cache::get("dashboard_version", 0)'],
            'increment version' => ['version-literal', "Cache::increment('calendar_version')"],
            'version in data key (double)' => ['versioned-key-literal', 'Cache::remember("dashboard:stats:v{$v}", 300, fn () => 1)'],
            'version in data key (concat)' => ['versioned-key-literal', "Cache::remember('point_map:regions:v'.\$v, 300, fn () => 1)"],
            'version read in a data key' => ['versioned-key-literal', "Cache::get('unit_map:center_types:v'.\$v)"],
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

    public function test_no_version_key_is_built_outside_the_service(): void
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
            ['ساخت دستیِ کلید نسخهٔ کش بیرون از CacheInvalidationService پیدا شد — همان کلیدِ هم‌زیستیِ خاموشِ #1290:'],
            $offenders,
            ['از remember()/cacheKey()/getVersion() سرویس استفاده کنید؛ اگر واقعاً استثناست، با دلیل به ALLOWLIST اضافه کنید.'],
        )));
    }

    public function test_every_namespace_literal_at_a_call_site_is_registered(): void
    {
        $registry = PruneStaleCache::NAMESPACES;
        $offenders = [];

        foreach ($this->scannedFiles() as $path) {
            $code = File::get(base_path($path));

            // Call sites of the versioning API. The `->` is load-bearing: it
            // keeps ordinary, unversioned `Cache::remember('unit_types_all', …)`
            // calls (deliberately out of scope, see the issue's split list) out
            // of a registry check they were never part of.
            preg_match_all('/->(?:increment|getVersion|cacheKey|remember)\(\s*\'([a-z0-9_]+)\'/', $code, $matches);

            foreach ($matches[1] as $namespace) {
                if (! in_array($namespace, $registry, true)) {
                    $offenders[] = "{$path} → «{$namespace}» در PruneStaleCache::NAMESPACES نیست";
                }
            }
        }

        // The AppServiceProvider lists are the biggest consumers of the
        // registry, and they call `$invalidate($namespaces)` through a variable
        // — the regex above never sees them. Extract their literals directly.
        $provider = File::get(base_path('app/Providers/AppServiceProvider.php'));

        preg_match_all('/(\$\w+Namespaces)\s*=\s*\[([^\]]*)\]/', $provider, $lists, PREG_SET_ORDER);

        expect($lists)->not->toBeEmpty('لیست‌های *Namespaces در AppServiceProvider پیدا نشدند — استخراج گارد شکسته است.');

        foreach ($lists as [, $variable, $body]) {
            preg_match_all('/\'([a-z0-9_]+)\'/', $body, $names);

            foreach ($names[1] as $namespace) {
                if (! in_array($namespace, $registry, true)) {
                    $offenders[] = "app/Providers/AppServiceProvider.php:{$variable} → «{$namespace}» در PruneStaleCache::NAMESPACES نیست";
                }
            }
        }

        // `ZabbixDevice::CACHE_NAMESPACE` holds a const, not a literal, so no
        // regex can see it. Assert the value directly instead.
        expect(in_array(ZabbixDevice::CACHE_NAMESPACE, $registry, true))
            ->toBeTrue('CACHE_NAMESPACE مدل ZabbixDevice در رجیستری نیست.');

        expect($offenders)->toBe([], implode("\n", array_merge(
            ['namespace مصرف‌شده در کد داخل PruneStaleCache::NAMESPACES نیست — کلیدهایش هرگز باطل نمی‌شوند:'],
            $offenders,
            ['نام درست را ثبت کنید، یا اگر namespace تازه است با commit جداگانه اضافه‌اش کنید — نه با نادیده‌گرفتن گارد.'],
        )));
    }

    public function test_registry_has_no_duplicates(): void
    {
        $duplicates = array_diff_assoc(PruneStaleCache::NAMESPACES, array_unique(PruneStaleCache::NAMESPACES));

        expect(array_values($duplicates))->toBe([], 'namespace تکراری در PruneStaleCache::NAMESPACES: '.implode(', ', $duplicates));
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
