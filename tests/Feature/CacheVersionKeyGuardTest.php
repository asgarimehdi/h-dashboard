<?php

namespace Tests\Feature;

use App\Console\Commands\PruneStaleCache;
use App\Models\ZabbixDevice;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1290 — guard that keeps manual cache-version keys from coming back.
 *
 * Every version key must be built through CacheInvalidationService
 * (getVersion()/cacheKey()/remember()/increment()). Scanning app/ and
 * resources/views/ only: tests/ holds legitimate *_version fixtures and
 * config/ defines the cache stores.
 */
final class CacheVersionKeyGuardTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const GROUPS = [
        'version-literal' => '/[\'"][^\'"]*_version[\'"]/',
        'versioned-key-literal' => '/Cache::(?:remember|get)\(\s*[\'"][^\'"]*:v/',
    ];

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'version-literal' => 'ساخت دستی کلید نسخه (*_version)',
        'versioned-key-literal' => 'کلید versioned دستی (Cache::remember/get با :v)',
    ];

    /**
     * @var array<string, array{groups: array<int, string>, reason: string}>
     */
    private const ALLOWLIST = [
        'app/Services/CacheInvalidationService.php' => [
            'groups' => ['version-literal'],
            'reason' => 'تنها جایی که جای‌نگهدار {ns}_version ساخته می‌شود.',
        ],
    ];

    public static function positiveSamples(): array
    {
        return [
            'version literal single' => ['version-literal', "'dashboard_version'"],
            'version literal double' => ['version-literal', '"hr_stats_version"'],
            'versioned key remember' => ['versioned-key-literal', "Cache::remember('dashboard:stats:v1', 300, fn)"],
            'versioned key get' => ['versioned-key-literal', "Cache::get('maps:regions:v2', 300)"],
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
            ['ساخت دستی کلید نسخه پیدا شد:'],
            $offenders,
        )));
    }

    public function test_every_namespace_literal_at_a_call_site_is_registered(): void
    {
        $namespaces = [];

        foreach ($this->scannedFiles() as $path) {
            $code = File::get(base_path($path));
            preg_match_all("/(?:increment|getVersion|cacheKey|remember)\(\s*'([a-z0-9_]+)'/", $code, $matches);

            foreach ($matches[1] as $ns) {
                $namespaces[] = $ns;
            }
        }

        // AppServiceProvider builds namespace lists via variables — read them too.
        $providerPath = base_path('app/Providers/AppServiceProvider.php');
        $providerCode = File::get($providerPath);
        $lines = explode("\n", $providerCode);
        foreach ([136, 141, 149, 157] as $lineNo) {
            if (isset($lines[$lineNo - 1])) {
                preg_match_all("/'([a-z0-9_]+)'/", $lines[$lineNo - 1], $m);
                foreach ($m[1] as $ns) {
                    $namespaces[] = $ns;
                }
            }
        }

        // ZabbixDevice::CACHE_NAMESPACE is not a string literal at the call site.
        expect(in_array(ZabbixDevice::CACHE_NAMESPACE, PruneStaleCache::NAMESPACES, true))->toBeTrue();

        $registered = PruneStaleCache::NAMESPACES;
        $unregistered = [];

        foreach (array_unique($namespaces) as $ns) {
            if (! in_array($ns, $registered, true)) {
                $unregistered[] = $ns;
            }
        }

        expect($unregistered)->toBe([], 'namespace ثبت‌نشده در رجیستری: '.implode(', ', $unregistered));
    }

    public function test_registry_has_no_duplicates(): void
    {
        $namespaces = PruneStaleCache::NAMESPACES;

        expect(count($namespaces))->toBe(count(array_unique($namespaces)));
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
