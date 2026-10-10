<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * زیرساخت دسترس‌پذیری (#957، قدم‌های ۱ تا ۴ و ۶).
 *
 * این فایل عمداً «رفتار» را می‌سنجد نه رشته‌های Blade: هر ادعا با DOM واقعیِ
 * رندرشده بررسی می‌شود، چون یک `aria-live` در جای دیگر صفحه هیچ کاری برای
 * همان پیام انجام نمی‌دهد.
 *
 * خارج از اسکوپ: `maximum-scale=1.0` در هر دو layout. طبق کامنت «تأیید گیت»
 * تصمیم محصولی مهدی است و تا گرفته نشدن دست نمی‌خورد.
 */
class AccessibilityInfrastructureTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();
    }

    /**
     * HTML را به یک XPath می‌دهد که بتوان آن را با عبارت‌های واقعی
     * (مثلاً `[@*[name()="mary-toast.window"]`) جست‌وجو کرد.
     *
     * libxml یک پارسر HTML4 است: `<!DOCTYPE>` را وسط سند جابه‌جا می‌کند و از
     * `<svg>` و نام‌های دارای «:» خطا می‌دهد. هر دو بی‌ضررند (عنصر را به‌عنوان
     * تگ ناشناخته می‌سازد) پس فقط خطاها را خاموش می‌کنیم و DOCTYPE را می‌بریم.
     */
    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML(
                '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'
                .preg_replace('/<!DOCTYPE[^>]*>/i', '', $html).'</body>'
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    /**
     * نزدیک‌ترین جدّ (یا خودِ گره) که با عبارت XPath مطابقت دارد.
     *
     * @param  string  $expression  نسبت به گره، مثلاً `ancestor-or-self::*[@aria-live="polite"]`
     */
    private function closestMatch(DOMXPath $xpath, DOMNode $node, string $expression): ?DOMElement
    {
        $result = $xpath->query($expression, $node);

        return ($result instanceof DOMNodeList && $result->length > 0) ? $result->item(0) : null;
    }

    // ---------------------------------------------------------------------
    // قدم ۱ — ناحیه‌های زنده برای مسیرهای اعلان
    // ---------------------------------------------------------------------

    public function test_toast_renders_inside_a_polite_live_region(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);
        Session::put('current_unit_name', 'واحد تست');

        $xpath = $this->xpath(Blade::render('<x-layouts.app>محتوا</x-layouts.app>'));

        // `@persist('mary-toaster')` تنها نشانهٔ پایدارِ ریشهٔ توست است؛
        // `@mary-toast.window` را پارسر HTML4 پاک می‌کند چون `@` نام معتبر نیست.
        $toast = $xpath->query('//*[@x-persist="mary-toaster"]')->item(0);
        $this->assertNotNull($toast, '<x-toast /> باید در لایوت رندر شود.');

        $this->assertNotNull(
            $this->closestMatch($xpath, $toast, 'ancestor-or-self::*[@aria-live="polite"]'),
            'توست باید داخل یک ناحیهٔ زندهٔ polite باشد؛ بدون آن هیچ اعلانی خوانده نمی‌شود.'
        );
    }

    public function test_login_validation_errors_are_announced(): void
    {
        $html = Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', '')
            ->call('login')
            ->assertHasErrors(['password'])
            ->html();

        $this->assertErrorBlockIsAnAlert($html);
    }

    public function test_change_password_validation_errors_are_announced(): void
    {
        ['user' => $user] = $this->createUserWithUnit([]);
        $this->actingAs($user);

        $html = Livewire::test('auth.changepassword')
            ->call('changePassword')
            ->assertHasErrors(['currentPassword'])
            ->html();

        $this->assertErrorBlockIsAnAlert($html);
    }

    public function test_ticket_creation_validation_errors_are_announced(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['create_ticket']);
        $this->actingAs($user);

        $html = Livewire::test('tickets.create')
            ->call('saveTicket')
            ->assertHasErrors(['subject'])
            ->html();

        $this->assertErrorBlockIsAnAlert($html);
    }

    /**
     * بلوک خطای MaryUI باید `role="alert"` داشته باشد؛ `alert` (نه `status`)
     * چون پیام خطا باید کاربر را همان لحظه متوقف کند، نه اینکه ته صف اعلان شود.
     */
    private function assertErrorBlockIsAnAlert(string $html): void
    {
        $xpath = $this->xpath($html);

        $alert = $xpath->query('//*[@role="alert"]')->item(0);
        $this->assertNotNull($alert, 'بلوک خطا باید role="alert" داشته باشد تا صفحه‌خوان آن را اعلام کند.');

        $this->assertSame(
            1,
            $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " alert-error ")]')->length,
            'باید دقیقاً یک بلوک خطا روی صفحه باشد.'
        );

        $this->assertNotSame(
            '',
            trim($xpath->query('//*[@role="alert"]//li')->item(0)?->textContent ?? ''),
            'بلوک alert باید متن خطا را در خود داشته باشد، نه فقط یک نقش خالی.'
        );
    }
}
