<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1307 — the guard that turns "works offline" from a claim into a contract.
 *
 * docs/agents/offline.md lists every browser runtime load; this test pins that
 * list. Any new external script/style/image/url()/config default/fetch/tile
 * layer outside ALLOWLIST fails here, so it needs a deliberate doc row and an
 * allowlist entry with a reason.
 *
 * Static scan, no database and no Vite: it reads the four shipped trees only.
 * public/build is gitignored (machine-dependent), public/js is mostly
 * licence/attribution hosts (the two documented network defaults are pinned
 * positively in method 6), tests/ deliberately carries external fixtures, and
 * .env* is deployment data. Do not widen the scope.
 */
final class NoExternalRuntimeAssetsTest extends TestCase
{
    /**
     * The four trees a browser load can be written in. config/ is included
     * because TILE_URL_TEMPLATE is the only real destination lever.
     *
     * @var array<int, string>
     */
    private const SCAN_ROOTS = ['resources/views', 'resources/js', 'resources/css', 'config'];

    /**
     * Only load shapes, never "every https?://" — attribution links, XML
     * namespaces and doc links are not loads. Each pattern names its URL in a
     * `url` capture so the reported line is the literal's line.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'html-attr-load' => '/<(?:script|link|img|source|iframe|embed|object|video|audio)\b[^>]{0,400}?\b(?:src|href|data)\s*=\s*["\']?(?P<url>(?:https?:)?\/\/[^"\' \t>]+)/is',
        'css-url' => '/(?:url\(\s*|@import\s+(?:url\(\s*)?)["\']?(?P<url>(?:https?:)?\/\/[^"\')\s]+)/i',
        // /s + a 400-char window: config/map.php spans lines; reporting the
        // literal (not the `env(`) keeps the failure message honest.
        'config-default' => '/\b(?:config|env)\(\s*[^,()]{1,200},\s*[^)]{0,400}?(?P<url>(?:https?:)?\/\/[^"\')]+)/s',
        'js-fallback' => '/\?\?\s*[\'"`](?P<url>(?:https?:)?\/\/[^\'"`]+)[\'"`]/',
        'load-call' => '/(?:\b(?:fetch|importScripts)\s*\(|\bnew\s+(?:Worker|WebSocket|EventSource)\s*\(|\baxios\s*\.\s*(?:get|post|put|delete|patch|request)\s*\(|\bserviceUrl\s*:)\s*[\'"`](?P<url>(?:https?:)?\/\/[^\'"`]+)/i',
        'tile-layer' => '/\bL\.tileLayer\s*\(\s*[\'"`](?P<url>(?:https?:)?\/\/[^\'"`]+)/',
    ];

    /**
     * Persian label per group for the failure message.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'html-attr-load' => 'تگ HTML با src/href/data بیرونی',
        'css-url' => 'url()/@import بیرونی در CSS',
        'config-default' => 'پیش‌فرضِ config()/env() با URL بیرونی',
        'js-fallback' => 'fallback جاوااسکریپت با URL بیرونی',
        'load-call' => 'fetch/axios/Worker/WebSocket/EventSource/serviceUrl بیرونی',
        'tile-layer' => 'L.tileLayer با literal بیرونی',
    ];

    /**
     * Loopback is "internal": these are local OSRM/Nominatim/APP_URL defaults.
     *
     * @var array<int, string>
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * The complete external runtime-load allowlist. Key is path + group + host,
     * never a line number: line numbers move with every edit and would keep a
     * dead entry looking "necessary".
     *
     * @var array<int, array{path: string, group: string, host: string, expected: int, kind: string, reason: string}>
     */
    private const ALLOWLIST = [
        [
            'path' => 'resources/views/op/index.php',
            'group' => 'html-attr-load',
            'host' => 'cdnjs.cloudflare.com',
            'expected' => 3,
            'kind' => 'local-only-route',
            'reason' => '/op فقط با app()->isLocal() ثبت می‌شود (routes/web.php:204)؛ بارگذاری واقعی است و ایشوی جداست.',
        ],
        [
            'path' => 'resources/views/components/layouts/app.blade.php',
            'group' => 'html-attr-load',
            'host' => 'cdn.jsdelivr.net',
            'expected' => 1,
            'kind' => 'commented',
            'reason' => 'از وقتی provider حذف شد کامنت است؛ assertionِ kind=commented اگر کسی uncomment کند قرمز می‌شود.',
        ],
        [
            'path' => 'config/map.php',
            'group' => 'config-default',
            'host' => 'tile.openstreetmap.org',
            'expected' => 1,
            'kind' => 'config-default',
            'reason' => 'پیش‌فرضِ TILE_URL_TEMPLATE، تنها اهرم واقعیِ مقصد کاشی؛ فقط literal کد در :19 شمرده می‌شود، نه کامنت :16.',
        ],
        [
            'path' => 'config/queue.php',
            'group' => 'config-default',
            'host' => 'sqs.us-east-1.amazonaws.com',
            'expected' => 1,
            'kind' => 'server-side',
            'reason' => 'پیشوند SQS، egress سرور، هرگز در مرورگر؛ از سینک مستند معاف است.',
        ],
        [
            'path' => 'resources/views/livewire/maps/map.blade.php',
            'group' => 'config-default',
            'host' => 'tile.openstreetmap.org',
            'expected' => 1,
            'kind' => 'fallback-live',
            'reason' => 'در :56 به Alpine.store(\'map\') داده می‌شود. تغییر آگاهانهٔ TILE_URL_TEMPLATE به هاستِ on-prem این مدخل و ردیف جدول را هم می‌خواهد.',
        ],
        [
            'path' => 'resources/views/livewire/units/map.blade.php',
            'group' => 'config-default',
            'host' => 'tile.openstreetmap.org',
            'expected' => 1,
            'kind' => 'fallback-live',
            'reason' => 'L.tileLayer(mapTileTemplate) در :234. تغییر آگاهانهٔ TILE_URL_TEMPLATE به هاستِ on-prem این مدخل و ردیف جدول را هم می‌خواهد.',
        ],
        [
            'path' => 'resources/views/livewire/maps/route.blade.php',
            'group' => 'config-default',
            'host' => 'tile.openstreetmap.org',
            'expected' => 1,
            'kind' => 'fallback-dead',
            'reason' => 'مقدار می‌گیرد، خوانده نمی‌شود. تغییر آگاهانهٔ TILE_URL_TEMPLATE به هاستِ on-prem این مدخل و ردیف جدول را هم می‌خواهد.',
        ],
        [
            'path' => 'resources/views/livewire/maps/route2.blade.php',
            'group' => 'config-default',
            'host' => 'tile.openstreetmap.org',
            'expected' => 1,
            'kind' => 'fallback-dead',
            'reason' => 'همان route.blade.php: مقدار می‌گیرد، خوانده نمی‌شود. تغییر آگاهانهٔ TILE_URL_TEMPLATE به هاستِ on-prem این مدخل و ردیف جدول را هم می‌خواهد.',
        ],
        [
            'path' => 'resources/js/map-store.js',
            'group' => 'js-fallback',
            'host' => 'tile.openstreetmap.org',
            'expected' => 1,
            'kind' => 'fallback-dead',
            'reason' => '?? امروز override می‌شود، فاز ۲ حذفش می‌کند. تغییر آگاهانهٔ TILE_URL_TEMPLATE به هاستِ on-prem این مدخل و ردیف جدول را هم می‌خواهد.',
        ],
    ];

    /**
     * Network defaults inside vendored libraries, pinned positively because
     * public/js is out of the scan. Keys are file paths, values the exact hosts
     * they must still contain.
     *
     * @var array<string, array<int, string>>
     */
    private const VENDORED_DEFAULTS = [
        'public/js/leaflet/leaflet-routing-machine.min.js' => ['router.project-osrm.org', 'api.mapbox.com'],
        'public/js/chart/exporting.js' => ['export-svg.highcharts.com'],
    ];

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function patternSamples(): array
    {
        $positives = [
            'html-attr-load' => '<script src="https://cdn.example.com/a.js"></script>',
            'css-url' => 'background: url(https://cdn.example.com/a.png)',
            'config-default' => "config('probe.url', 'https://example.invalid/x')",
            'js-fallback' => "const url = this.config.tileUrl ?? 'https://example.invalid/{z}/{x}/{y}.png';",
            'load-call' => "fetch('https://example.invalid/probe')",
            'tile-layer' => "L.tileLayer('https://example.invalid/{z}/{x}/{y}.png')",
        ];

        $rows = [];

        foreach ($positives as $group => $sample) {
            $rows["{$group} positive"] = [$group, $sample, true];
            $rows["{$group} relative src"] = [$group, '<script src="/js/x.js"></script>', false];
            $rows["{$group} relative css url"] = [$group, 'background: url(/img/x.png)', false];
        }

        // config/map.php:16 is a comment literal directly above the counted
        // :19 literal — no comment stripping, so the regex must not match it.
        $rows['config-default commented literal'] = ['config-default', '// https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', false];
        $rows['load-call relative fetch'] = ['load-call', "fetch('/api/x')", false];
        $rows['tile-layer variable'] = ['tile-layer', 'L.tileLayer(mapTileTemplate)', false];
        $rows['html-attr relative href'] = ['html-attr-load', '<link href="/css/x.css">', false];

        return $rows;
    }

    /**
     * The six loopback literals config/ and the route pages legitimately carry.
     *
     * @return array<string, array{0: string}>
     */
    public static function loopbackSamples(): array
    {
        return [
            'config/app.php:55' => ['http://localhost'],
            'config/cors.php:22' => ['http://localhost:8000'],
            'config/mail.php:49' => ['http://localhost'],
            'route.blade.php:14' => ['http://127.0.0.1:5000'],
            'route2.blade.php:20' => ['http://127.0.0.1:5000'],
            'route2.blade.php:21' => ['http://127.0.0.1:8088'],
        ];
    }

    #[DataProvider('patternSamples')]
    public function test_each_pattern_group_matches_its_positive_sample(string $group, string $sample, bool $shouldMatch): void
    {
        expect(preg_match(self::GROUPS[$group], $sample) === 1)->toBe(
            $shouldMatch,
            $shouldMatch
                ? "گروه «{$group}» نمونهٔ مثبت خودش را مچ نمی‌کند — گارد بی‌صدا شکسته است: {$sample}"
                : "گروه «{$group}» نمونهٔ منفی را مچ می‌کند — regex بیش‌ازحد فراگیر است: {$sample}",
        );

        foreach (self::loopbackSamples() as $label => [$url]) {
            expect($this->isInternalUrl($url))->toBeTrue("نمونهٔ داخلی {$label} به‌عنوان بیرونی دیده شد: {$url}");
        }
    }

    public function test_scan_roots_exist_and_contain_files(): void
    {
        $empty = [];
        $total = 0;

        foreach (self::SCAN_ROOTS as $root) {
            if (! File::isDirectory(base_path($root))) {
                $empty[] = "{$root} → پوشه وجود ندارد";

                continue;
            }

            $count = count(File::allFiles(base_path($root)));
            $total += $count;

            if ($count === 0) {
                $empty[] = "{$root} → خالی است";
            }
        }

        expect($empty)->toBe([], "دامنهٔ اسکن ناقص است:\n".implode("\n", $empty));
        expect($total)->toBeGreaterThanOrEqual(100, "دامنهٔ اسکن فقط {$total} فایل دارد — یک پوشه جابه‌جا شده و اسکن خالی «سبزِ دروغین» می‌دهد.");
    }

    public function test_no_external_runtime_load_exists_outside_the_allowlist(): void
    {
        $findings = [];

        foreach ($this->scannedFiles() as $path) {
            foreach ($this->findingsInFile($path) as $finding) {
                $findings[] = $finding;
            }
        }

        usort($findings, fn (array $a, array $b): int => [$a['path'], $a['line'], $a['group'], $a['url']] <=> [$b['path'], $b['line'], $b['group'], $b['url']]);

        $consumed = [];
        $offenders = [];

        foreach ($findings as $finding) {
            $covered = false;

            foreach (self::ALLOWLIST as $index => $entry) {
                if ($finding['path'] !== $entry['path'] || $finding['group'] !== $entry['group'] || ! str_contains($finding['host'], $entry['host'])) {
                    continue;
                }

                $consumed[$index] = ($consumed[$index] ?? 0) + 1;
                $covered = $consumed[$index] <= $entry['expected'];

                break;
            }

            if (! $covered) {
                $offenders[] = "{$finding['path']}:{$finding['line']} → {$this->label($finding['group'])} → {$finding['url']}";
            }
        }

        expect($offenders)->toBe([], implode("\n", array_merge(
            ['بارگذاری بیرونیِ زمان اجرا خارج از allowlist پیدا شد — استقرار بدون اینترنت می‌شکند:'],
            $offenders,
            ['اگر واقعاً لازم است: یک ردیف در docs/agents/offline.md (بخش Browser runtime loads) و یک مدخل در ALLOWLIST همین تست با دلیل اضافه کنید.'],
        )));
    }

    public function test_every_allowlist_entry_still_matches_code(): void
    {
        $stale = [];

        foreach (self::ALLOWLIST as $entry) {
            if (! File::exists(base_path($entry['path']))) {
                $stale[] = "{$entry['path']} → فایل وجود ندارد";

                continue;
            }

            $matches = array_values(array_filter(
                $this->findingsInFile($entry['path']),
                fn (array $finding): bool => $finding['group'] === $entry['group'] && str_contains($finding['host'], $entry['host']),
            ));

            if ($matches === []) {
                $stale[] = "{$entry['path']} → {$entry['group']} → {$entry['host']} دیگر در کد نیست؛ مدخل و ردیفش را حذف کنید.";

                continue;
            }

            if (count($matches) !== $entry['expected']) {
                $stale[] = "{$entry['path']} → {$entry['group']} → {$entry['host']}: انتظار {$entry['expected']} مورد، یافت ".count($matches);
            }

            if ($entry['kind'] === 'commented') {
                $code = File::get(base_path($entry['path']));

                foreach ($matches as $match) {
                    if (! $this->isInsideHtmlComment($code, $match['offset'])) {
                        $stale[] = "{$entry['path']}:{$match['line']} → بارگذاری از کامنت خارج شده؛ یا کامنتش کنید یا ردیف جدول و مدخل را به‌روز کنید.";
                    }
                }
            }
        }

        expect($stale)->toBe([], "مدخلِ بی‌استفاده یا ناهم‌گام در ALLOWLIST:\n".implode("\n", $stale));
    }

    public function test_allowlist_and_offline_doc_name_the_same_external_sources(): void
    {
        $rows = $this->offlineTableRows();

        expect(count($rows))->toBeGreaterThanOrEqual(
            19,
            'جدول «Browser runtime loads» در docs/agents/offline.md پیدا نشد یا کمتر از ۱۹ ردیف دارد — بازچینش مستند، allowlist را بی‌صدا حذف کرده است.',
        );

        $missing = [];

        foreach (self::ALLOWLIST as $entry) {
            if ($entry['kind'] === 'server-side') {
                continue;
            }

            $found = false;

            foreach ($rows as $row) {
                if ($this->locationNamesPath($row['location'], $entry['path'])) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $missing[] = "{$entry['path']} → هیچ ردیفی در ستون Location جدول نیست";
            }
        }

        $unresolved = [];

        foreach ($rows as $row) {
            if (! str_contains(mb_strtolower($row['scope']), 'external')) {
                continue;
            }

            if (! $this->rowResolvesToKnownSource($row['location'])) {
                $unresolved[] = "ردیف {$row['number']} ({$row['location']}) → نه در ALLOWLIST است نه در VENDORED_DEFAULTS";
            }
        }

        expect(array_merge($missing, $unresolved))->toBe([], implode("\n", array_merge(
            ['allowlist تست و جدول docs/agents/offline.md هم‌گام نیستند:'],
            $missing,
            $unresolved,
            ['مدخل config/queue.php (kind=server-side، egress سرور) از این سینک معاف است.'],
        )));
    }

    public function test_vendored_network_defaults_are_still_the_documented_two(): void
    {
        expect(self::VENDORED_DEFAULTS)->toHaveCount(2);

        $missing = [];

        foreach (self::VENDORED_DEFAULTS as $path => $hosts) {
            if (! File::exists(base_path($path))) {
                $missing[] = "{$path} → فایل وجود ندارد";

                continue;
            }

            $code = File::get(base_path($path));

            foreach ($hosts as $host) {
                if (! str_contains($code, $host)) {
                    $missing[] = "{$path} → {$host}";
                }
            }
        }

        expect($missing)->toBe([], "پیش‌فرضِ شبکه‌ایِ vendored مستند (ردیف ۱۸/۱۹) عوض شده:\n".implode("\n", $missing));
    }

    /**
     * @return array<int, string>
     */
    private function scannedFiles(): array
    {
        $paths = [];

        foreach (self::SCAN_ROOTS as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                $paths[] = $this->relativePath($file->getPathname());
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return array<int, array{path: string, group: string, host: string, url: string, line: int, offset: int}>
     */
    private function findingsInFile(string $path): array
    {
        $code = File::get(base_path($path));
        $found = [];

        foreach (self::GROUPS as $group => $pattern) {
            if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($matches as $match) {
                /** @var array{0: string, 1: int} $captured */
                $captured = $match['url'];
                [$url, $offset] = $captured;

                if ($this->isInternalUrl($url)) {
                    continue;
                }

                $found[] = [
                    'path' => $path,
                    'group' => $group,
                    'host' => $this->hostOf($url),
                    'url' => $url,
                    'line' => substr_count(substr($code, 0, $offset), "\n") + 1,
                    'offset' => $offset,
                ];
            }
        }

        return $found;
    }

    /**
     * @return array<int, array{number: string, location: string, scope: string}>
     */
    private function offlineTableRows(): array
    {
        $lines = preg_split('/\R/', File::get(base_path('docs/agents/offline.md'))) ?: [];
        $start = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, '| # | Location | Type | Destination | Scope | Override |')) {
                $start = $index;

                break;
            }
        }

        if ($start === null) {
            return [];
        }

        $rows = [];

        for ($index = $start + 2; $index < count($lines); $index++) {
            $line = trim($lines[$index]);

            if ($line === '' || ! str_starts_with($line, '|')) {
                break;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            if (count($cells) < 5) {
                continue;
            }

            $rows[] = ['number' => $cells[0], 'location' => $cells[1], 'scope' => $cells[4]];
        }

        return $rows;
    }

    private function locationNamesPath(string $location, string $path): bool
    {
        return str_contains($location, $path) || str_contains($location, basename($path));
    }

    private function rowResolvesToKnownSource(string $location): bool
    {
        foreach (self::ALLOWLIST as $entry) {
            if ($entry['kind'] !== 'server-side' && $this->locationNamesPath($location, $entry['path'])) {
                return true;
            }
        }

        foreach (array_keys(self::VENDORED_DEFAULTS) as $path) {
            if ($this->locationNamesPath($location, $path)) {
                return true;
            }
        }

        return false;
    }

    private function hostOf(string $url): string
    {
        return (string) parse_url(str_starts_with($url, '//') ? 'http:'.$url : $url, PHP_URL_HOST);
    }

    private function isInternalUrl(string $url): bool
    {
        return in_array($this->hostOf($url), self::LOOPBACK_HOSTS, true);
    }

    private function isInsideHtmlComment(string $code, int $offset): bool
    {
        $before = substr($code, 0, $offset);

        return strrpos($before, '<!--') !== false && strrpos($before, '<!--') > strrpos($before, '-->');
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
