<?php

namespace Tests\Feature\Accessibility;

use Tests\TestCase;

/**
 * هیچ دکمهٔ icon-only نباید بدون نامِ قابل‌دسترس رندر شود.
 *
 * چرا این تست وجود دارد: در #956 معلوم شد ۷۸ سایت `<x-button icon=…>` در ۲۲ فایل
 * هیچ `label=` و هیچ `aria-label=` نداشتند. آیکن داخلی `blade-icons` با
 * `aria-hidden="true"` رندر می‌شود، پس صفحه‌خوان نامی برای دکمه پیدا نمی‌کند — یعنی
 * «ویرایش» و «حذف» برای کاربر صفحه‌خوان یکسان‌اند. و بدترین‌ها همین‌ها هستند:
 * حذف کاربر، حذف سخت‌افزار، حذف کامنت، بازگردانی، حذف واحد.
 *
 * این تست **ایستا** است (کد Blade را می‌خواند، نه DOM رندرشده) تا در ژب
 * Tests & Coverage — که گیت اجباری است — اجرا شود. همتای e2e
 * (`tests/e2e/navigation/accessible-names.spec.ts`) همان خاصیت را روی DOM واقعی
 * چند صفحهٔ کلیدی می‌سنجد؛ این یکی کل درخت را نگه می‌دارد.
 *
 * نکتهٔ پیاده‌سازی: تگ‌ها باید «آگاه به کوتیشن» اسکن شوند. یک رگرسیون سادهٔ
 * `<x-button\b(.*?)(/>|>)` روی `wire:click="foo({{ $bar->id }})"` در `->` می‌بُرد و
 * بی‌صدا زیرمجموعه‌ای از سایت‌ها را می‌بیند. کامنت‌های `{{-- --}}` هم باید حذف شوند،
 * وگرنه کدِ کامنت‌شدهٔ `theme-selector` به‌عنوان سایت زنده شمرده می‌شود.
 */
class IconButtonAccessibleNameTest extends TestCase
{
    /**
     * تگ‌هایی که نامِ قابل‌دسترس می‌دهند.
     *
     * `x-ui.icon-button` کامپوننت مرکزی #956 است: `name` پارامتر اجباری آن است و
     * خودش `aria-label` را می‌سازد، پس آوردنش به‌معنای داشتن نام است.
     *
     * @var array<int, string>
     */
    private const NAMING_ATTRIBUTES = ['label=', 'aria-label=', 'name='];

    public function test_no_icon_only_button_lacks_an_accessible_name(): void
    {
        $violations = [];

        foreach ($this->bladeFiles() as $path) {
            foreach ($this->unnamedIconButtons($path) as [$line, $tag]) {
                $violations[] = sprintf('%s:%d  %s', $this->relative($path), $line, $tag);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "دکمه‌های icon-only بدون نامِ قابل‌دسترس:\n".implode("\n", $violations)
        );
    }

    public function test_every_named_icon_button_has_a_non_empty_name(): void
    {
        // «نامِ خالی» بدتر از «بی‌نام» نیست: تست بالا آن را سالم می‌شمارد
        // (`label=""` هنوز `label=` دارد) ولی صفحه‌خوان باز هم چیزی برای خواندن ندارد.
        $empty = [];

        foreach ($this->bladeFiles() as $path) {
            $source = $this->stripBladeComments(file_get_contents($path));

            foreach ($this->tags($source, 'x-button') as [$offset, $tag]) {
                if (! $this->hasAttribute($tag, 'icon')) {
                    continue;
                }

                foreach (self::NAMING_ATTRIBUTES as $attribute) {
                    $name = rtrim($attribute, '=');

                    if ($this->attributeValue($tag, $name) === '') {
                        $empty[] = sprintf(
                            '%s:%d  %s=""',
                            $this->relative($path),
                            substr_count(substr($source, 0, $offset), "\n") + 1,
                            $name
                        );
                    }
                }
            }
        }

        $this->assertSame([], $empty, "دکمه‌هایی با نامِ خالی:\n".implode("\n", $empty));
    }

    public function test_the_notification_bell_button_exposes_a_name(): void
    {
        // زنگ اعلان یک `<button>` ساده است، نه `x-button`، پس اسکنر بالا آن را نمی‌بیند.
        $path = resource_path('views/livewire/notifications/bell.blade.php');
        $source = $this->stripBladeComments(file_get_contents($path));

        $this->assertSame(
            1,
            count(array_filter(
                $this->tags($source, 'button'),
                fn (array $tag): bool => str_contains($tag[1], 'wire:click="toggleDropdown"')
            )),
            'باید دقیقاً یک دکمهٔ toggleDropdown در زنگ اعلان باشد.'
        );

        $bell = array_values(array_filter(
            $this->tags($source, 'button'),
            fn (array $tag): bool => str_contains($tag[1], 'wire:click="toggleDropdown"')
        ))[0][1];

        $this->assertMatchesRegularExpression(
            '/\baria-label\s*=\s*"[^"]+"/',
            $bell,
            'دکمهٔ زنگ اعلان باید aria-label داشته باشد.'
        );
    }

    public function test_the_notification_bell_hides_its_unread_badge_from_the_accessible_name(): void
    {
        // شمارنده فقط تزئینی است: عددش داخل `aria-label` دکمه آمده، پس اگر دوباره
        // خوانده شود صفحه‌خوان عدد را دوبار می‌شنود.
        $path = resource_path('views/livewire/notifications/bell.blade.php');
        $source = $this->stripBladeComments(file_get_contents($path));

        $this->assertMatchesRegularExpression(
            '/<span\s+aria-hidden="true"[^>]*>\s*\{\{\s*\$unreadCount/',
            $source,
            'نشان شمارندهٔ خوانده‌نشدهها باید aria-hidden باشد.'
        );
    }

    /**
     * دکمه‌های icon-onlyِ بدون نام در یک فایل.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function unnamedIconButtons(string $path): array
    {
        $source = $this->stripBladeComments(file_get_contents($path));
        $found = [];

        foreach ($this->tags($source, 'x-button') as [$offset, $tag]) {
            if (! $this->hasAttribute($tag, 'icon')) {
                continue;
            }

            foreach (self::NAMING_ATTRIBUTES as $attribute) {
                if ($this->hasAttribute($tag, rtrim($attribute, '='))) {
                    continue 2;
                }
            }

            $found[] = [substr_count(substr($source, 0, $offset), "\n") + 1, $this->condense($tag)];
        }

        foreach ($this->tags($source, 'x-ui.icon-button') as [$offset, $tag]) {
            // کامپوننت مرکزی خودش نام را اجباری می‌کند؛ این فقط صفتِ نام را چک می‌کند.
            if ($this->hasAttribute($tag, 'aria-label')) {
                continue;
            }

            if (trim($this->attributeValue($tag, 'name')) === '') {
                $found[] = [
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                    $this->condense($tag),
                ];
            }
        }

        return $found;
    }

    /**
     * آیا صفت با همین نام وجود دارد؟
     *
     * مرز صفت لازم است: `str_contains($tag, 'label=')` روی یک صفتی مثل
     * `badge-label="…"` هم TRUE می‌شود و آن‌وقت دکمهٔ بی‌نام «نام‌دار» شمرده
     * می‌شود. بنابراین فقط وقتی قبل از نام، ابتدای تگ، فاصله، یا `:` باشد قبول است
     * — `:` چون `:label="…"` نحوِ مقیدِ Blade است و همان `label` حساب می‌شود
     * (دو سایت پیوستِ تیکت از این نوع‌اند و نام‌دارند).
     */
    private function hasAttribute(string $tag, string $name): bool
    {
        return preg_match('/(?:^|[\s:])'.preg_quote($name, '/').'\s*=/', $tag) === 1;
    }

    /**
     * مقدارِ صفت، یا `null` وقتی صفت وجود ندارد. کوتیشن تکی هم پذیرفته می‌شود.
     */
    private function attributeValue(string $tag, string $name): ?string
    {
        if (! preg_match('/(?:^|[\s:])'.preg_quote($name, '/').'\s*=\s*(["\'])(.*?)\1/s', $tag, $m)) {
            return null;
        }

        return $m[2];
    }

    /**
     * اسکن آگاه به کوتیشن: تا `>` بیرون از داخل کوتیشن جلو می‌رود.
     *
     * @return array<int, array{0: int, 1: string}> [offset, tag]
     */
    private function tags(string $source, string $name): array
    {
        $open = '<'.$name;
        $tags = [];
        $cursor = 0;

        while (($start = strpos($source, $open, $cursor)) !== false) {
            $next = substr($source, $start + strlen($open), 1);

            // مرز واژه: `<x-button` نباید با `<x-buttons` یا `<x-button-group` اشتباه شود
            if ($next !== '' && ! in_array($next, [' ', "\n", "\t", "\r", '/', '>'], true)) {
                $cursor = $start + strlen($open);

                continue;
            }

            $i = $start + strlen($open);
            $length = strlen($source);
            $end = null;

            while ($i < $length) {
                $char = $source[$i];

                if ($char === '"' || $char === "'") {
                    $close = strpos($source, $char, $i + 1);

                    if ($close === false) {
                        break;
                    }

                    $i = $close + 1;

                    continue;
                }

                if ($char === '>') {
                    $end = $i;
                    break;
                }

                $i++;
            }

            if ($end === null) {
                break;
            }

            $tags[] = [$start, substr($source, $start, $end - $start + 1)];
            $cursor = $end + 1;
        }

        return $tags;
    }

    private function stripBladeComments(string $source): string
    {
        // طول را حفظ می‌کنیم تا شمارهٔ خط گزارش‌شده به خط واقعی فایل بخواند.
        return preg_replace_callback(
            '/\{\{--(.*?)--\}\}/s',
            fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source
        );
    }

    /**
     * @return array<int, string>
     */
    private function bladeFiles(): array
    {
        $files = glob(resource_path('views').'/**/*.blade.php') ?: [];

        // glob تک‌سطحی است؛ عمق‌های تودرتو را هم بیاور
        $directory = new \RecursiveDirectoryIterator(resource_path('views'));
        $iterator = new \RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(resource_path().'/', '', $path);
    }

    private function condense(string $tag): string
    {
        return trim(preg_replace('/\s+/', ' ', $tag));
    }
}
