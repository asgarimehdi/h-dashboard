<?php

namespace Tests\Feature\Accessibility;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * قرارداد رندرِ `x-ui.icon-button` (#956).
 *
 * ادعای مرکزی ایشو این است که `aria-label` از `x-button` به `<button>` می‌رسد؛
 * ادعای دوم اینکه کامپوننت مرکزی رفتار `x-button` را خراب نمی‌کند. اگر پاس‌دادن
 * صفت‌ها یا `$slot` در کامپوننت غلط باشد، ۷۸ سایتِ مهاجرت‌کرده هم‌زمان می‌شکنند.
 *
 * تستِ اصلی **تفاضلی** است: رندرِ کامپوننت باید با رندرِ `<x-button>` خام با همان
 * صفت‌ها یکی باشد، جز `aria-label` و `wire:key`. این تنها راهی است که ثابت کند
 * wrapper چیزی را در مسیر `{{ $attributes }}` گم نمی‌کند — که یک‌بار اتفاق افتاد:
 * `tooltip-right` و `no-wire-navigate` بی‌سروصدا حذف شدند و فقط با مقایسهٔ کاملِ
 * رندر دیده شدند. ورودی‌ها از صفت‌های واقعیِ همان ۷۸ سایت برداشته شده‌اند.
 */
class IconButtonComponentTest extends TestCase
{
    /**
     * صفت‌های واقعیِ سایت‌های مهاجرت‌کرده — هر کدام یک شکل متفاوت از مصرف MaryUI.
     *
     * @return array<string, array{string, string}> [نامِ حالت, صفت‌های مشترکِ هر دو رندر]
     */
    private static function cases(): array
    {
        return [
            'icon only' => 'icon="o-trash" class="btn-ghost btn-sm text-error"',
            'destructive with confirm + spinner' => 'icon="o-trash" wire:click="delete({{ $user->id }})"'
                .' wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error"',
            'save + spinner' => 'icon="o-check" wire:click="updateEstekhdam" class="btn-ghost btn-sm text-success" spinner',
            'cancel' => 'icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-sm"',
            'title attribute' => 'icon="o-signal" class="btn-ghost btn-circle btn-sm" title="تست اتصال" spinner',
            'dynamic icon' => 'icon="{{ $active ? \'o-eye-slash\' : \'o-eye\' }}" wire:click="toggle(1)" class="btn-ghost"',
            'submit + tooltip + no-wire-navigate' => 'icon="o-power" type="submit" class="btn-circle btn-ghost btn-xs"'
                .' tooltip-right="logoff" no-wire-navigate',
            'link + tooltip + no-wire-navigate' => 'icon="o-arrows-right-left" class="btn-ghost btn-xs" tooltip-right="تغییر حوزه"'
                .' no-wire-navigate link="/select-context"',
            'external link' => 'icon="o-arrow-down-tray" link="{{ $url }}" class="btn-xs btn-ghost text-primary"'
                .' external target="_blank"',
            'plain link' => 'icon="o-paper-clip" link="{{ $url }}" class="btn-xs btn-ghost text-primary"',
            'alpine class binding' => 'icon="o-funnel" :class="$showFilters ? \'btn-primary\' : \'btn-ghost\'"'
                .' wire:click="$toggle(\'showFilters\')"',
            'alpine disabled binding' => 'icon="o-chevron-right" class="btn-circle btn-sm" :disabled="$page <= 1"'
                .' wire:click="historyPage(3)"',
            'alpine click alongside wire click' => 'icon="o-pencil" wire:click="editPermission({{ $p->id }})" class="btn-ghost btn-sm text-primary"'
                .' @click="$wire.modal = true"',
            'responsive create' => 'class="btn-success" wire:click="openModalForCreate" responsive icon="o-plus"',
            'responsive create with alpine' => 'class="btn-success" @click="$wire.modal = true" responsive icon="o-plus"',
            'tooltip left' => 'icon="o-power" class="btn-xs" tooltip-left="خروج" link="/logout"',
        ];
    }

    public function test_it_renders_exactly_like_a_bare_x_button_plus_the_aria_label(): void
    {
        $data = [
            'user' => (object) ['id' => 7],
            'p' => (object) ['id' => 3],
            'active' => true,
            'url' => 'https://example.test/file.pdf',
            // `:class` و `:disabled` را Blade سمت‌سرور ارزیابی می‌کند (پیش از این
            // ایشو هم همین‌طور بوده)؛ این‌ها فقط ورودیِ تست‌اند، نه چیزی که
            // کامپوننت مرکزی قرار است درستش کند.
            'showFilters' => true,
            'page' => 2,
        ];
        $mismatched = [];

        foreach (self::cases() as $case => $attributes) {
            $bare = $this->normalise(Blade::render('<x-button '.$attributes.' />', $data));
            $wrapped = $this->normalise(
                Blade::render('<x-ui.icon-button name="نام" '.$attributes.' />', $data)
            );

            if ($bare !== $wrapped) {
                $mismatched[$case] = "bare:\n".$bare."\nwrapped:\n".$wrapped;
            }
        }

        $this->assertSame([], $mismatched, 'کامپوننت مرکزی نباید رندرِ <x-button> را تغییر دهد.');
    }

    public function test_it_emits_the_accessible_name(): void
    {
        $html = Blade::render('<x-ui.icon-button name="ویرایش کاربر" icon="o-pencil" />');

        $this->assertStringContainsString('aria-label="ویرایش کاربر"', $html);
        $this->assertStringContainsString('<button', $html);
    }

    public function test_it_emits_the_accessible_name_on_links_too(): void
    {
        $html = Blade::render('<x-ui.icon-button name="تست اتصال" icon="o-signal" link="/ping" />');

        $this->assertStringContainsString('<a ', $html);
        $this->assertStringContainsString('href="/ping"', $html);
        $this->assertStringContainsString('aria-label="تست اتصال"', $html);
    }

    public function test_it_keeps_a_visible_label_for_responsive_buttons(): void
    {
        // توصیهٔ #2: برچسبِ دیدنی زیر `lg` با `hidden lg:block` پنهان می‌شود،
        // پس `aria-label` تنها چیزی است که آن‌جا نام را حمل می‌کند — هر دو لازم‌اند.
        $html = Blade::render(
            '<x-ui.icon-button name="کاربر جدید" label="کاربر جدید" icon="o-plus" responsive />'
        );

        $this->assertStringContainsString('aria-label="کاربر جدید"', $html);
        $this->assertStringContainsString('hidden lg:block', $html);
    }

    public function test_it_forwards_slot_content(): void
    {
        $html = Blade::render('<x-ui.icon-button name="گزینه" icon="o-plus">متن داخلی</x-ui.icon-button>');

        $this->assertStringContainsString('متن داخلی', $html);
        $this->assertStringContainsString('aria-label="گزینه"', $html);
    }

    public function test_the_name_is_required(): void
    {
        // بدون `name` باید loud بماند، نه اینکه بی‌نام رندر شود.
        $this->expectException(\Throwable::class);

        Blade::render('<x-ui.icon-button icon="o-trash" />');
    }

    /**
     * نرمال‌سازی: حذف `wire:key` (uuid از props ساخته می‌شود و عمداً فرق می‌کند)،
     * حذف `aria-label` افزودهٔ کامپوننت، و یکدست‌کردن فاصله‌ها.
     *
     * فاصله‌ها یکدست می‌شوند چون وقتی `aria-label` از وسط کیسهٔ صفت حذف می‌شود یک
     * فاصلهٔ اضافه باقی می‌ماند؛ مقایسهٔ ساختاری معنا دارد، نه فاصلهٔ داخلی Blade.
     */
    private function normalise(string $html): string
    {
        $html = preg_replace('/wire:key="[^"]*"/', '', $html) ?? $html;
        $html = preg_replace('/aria-label="[^"]*"/', '', $html) ?? $html;

        return trim(preg_replace('/\s+/', ' ', $html) ?? $html);
    }
}
