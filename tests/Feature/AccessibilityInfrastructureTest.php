<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Semat;
use App\Models\Ticket;
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
        $xpath = $this->xpath($this->renderAppLayout());

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

    // ---------------------------------------------------------------------
    // قدم ۲ — هدرهای جدول مرتب‌شونده: اعلام وضعیت و دسترسی با صفحه‌کلید
    // ---------------------------------------------------------------------

    public function test_the_sorted_header_announces_ascending_and_is_keyboard_reachable(): void
    {
        $xpath = $this->sematTableXpath();

        // kargozini.semat ستون‌های id و name را مرتب می‌کند و پیش‌فرض روی
        // id صعودی است؛ ستون آخر ستونِ «عملیات» است که اصلاً مرتب نمی‌شود.
        $sorted = $this->headerAt($xpath, 0);
        $this->assertSame('ascending', $sorted->getAttribute('aria-sort'));
        $this->assertSame('0', $sorted->getAttribute('tabindex'));

        $otherSortable = $this->headerAt($xpath, 1);
        $this->assertSame('none', $otherSortable->getAttribute('aria-sort'));
        $this->assertSame('0', $otherSortable->getAttribute('tabindex'));
    }

    public function test_sorting_another_column_moves_the_announcement(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);
        $this->seedLookupTables();
        Semat::create(['name' => 'عنوان الف']);

        $component = Livewire::test('kargozini.semat')
            ->set('sortBy', ['column' => 'name', 'direction' => 'desc']);

        $xpath = $this->xpath($component->html());

        $this->assertSame('none', $this->headerAt($xpath, 0)->getAttribute('aria-sort'));
        $this->assertSame('descending', $this->headerAt($xpath, 1)->getAttribute('aria-sort'));
    }

    public function test_non_sortable_headers_get_no_affordances(): void
    {
        $xpath = $this->sematTableXpath();

        $actions = $this->headerAt($xpath, 2);
        $this->assertSame('', $actions->getAttribute('aria-sort'), 'ستون غیرقابل‌مرتب نباید aria-sort بگیرد.');
        $this->assertSame('', $actions->getAttribute('tabindex'), 'ستون غیرقابل‌مرتب نباید در ترتیب تب بیاید.');
    }

    public function test_sortable_headers_bind_enter_and_space_to_the_same_action_as_the_click(): void
    {
        // `<th>` دکمه نیست: tabindex آن را قابل‌رسیدن می‌کند اما بدون
        // @keydown کاربر می‌تواند روی کنترل مرتب‌سازی فوکوس کند و هیچ اتفاقی
        // نمی‌افتد. قرارداد این است که صفحه‌کلید دقیقاً همان کار کلیک را بکند.
        $html = $this->sematTableHtml();

        preg_match('/<th[^>]*aria-sort="ascending"[^>]*>/', $html, $header);
        $this->assertNotEmpty($header, 'هدرِ مرتب‌شده باید aria-sort داشته باشد.');

        preg_match_all(
            '/@(click|keydown\.enter\.prevent|keydown\.space\.prevent)="([^"]*)"/',
            $header[0],
            $directives,
            PREG_SET_ORDER
        );

        $payloads = [];
        foreach ($directives as [, $directive, $expression]) {
            $payloads[$directive] = preg_replace('/\s+/', ' ', trim($expression));
        }

        $this->assertSame(
            ['click', 'keydown.enter.prevent', 'keydown.space.prevent'],
            array_keys($payloads),
            'کلیک، Enter و Space باید هر سه به همان sort متصل باشند.'
        );

        $this->assertStringContainsString("\$wire.set('sortBy'", $payloads['click']);
        $this->assertSame($payloads['click'], $payloads['keydown.enter.prevent'], 'Enter باید مثل کلیک عمل کند.');
        $this->assertSame($payloads['click'], $payloads['keydown.space.prevent'], 'Space هم باید مثل کلیک عمل کند.');
    }

    // ---------------------------------------------------------------------
    // قدم ۲ — aria-current روی آیتم فعال منو
    // ---------------------------------------------------------------------

    public function test_the_active_sidebar_link_is_marked_as_the_current_page(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $xpath = $this->xpath($this->get('/users')->assertOk()->getContent());

        $current = $xpath->query('//a[@aria-current="page"]');
        $this->assertSame(
            1,
            $current->length,
            'دقیقاً یک لینک باید aria-current="page" داشته باشد — همان صفحه‌ای که در آن هستیم.'
        );

        $this->assertSame('/users', $current->item(0)->getAttribute('href'));
    }

    public function test_inactive_sidebar_links_are_not_marked_as_current(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $xpath = $this->xpath($this->get('/users')->assertOk()->getContent());

        $this->assertSame(
            0,
            $xpath->query('//nav//a[@href="/activity-log"][@aria-current]')->length,
            'لینکی که صفحهٔ جاری نیست نباید aria-current بگیرد؛ اعلامِ اشتباه از نبودِ اعلام بدتر است.'
        );
    }

    public function test_the_active_link_keeps_the_class_mary_menu_sub_reads(): void
    {
        // Mary's MenuSub decides a submenu is open with
        // `Str::contains($slot, 'mary-active-menu')` over the ALREADY RENDERED
        // child HTML. Dropping that class while adding aria-current would leave
        // every submenu silently collapsed — a regression no assertion on the
        // <a> alone would catch, so the enclosing <details> is checked too.
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $xpath = $this->xpath($this->get('/users')->assertOk()->getContent());

        $active = $xpath->query('//a[@aria-current="page"]')->item(0);

        $this->assertStringContainsString(
            'mary-active-menu',
            $active->getAttribute('class'),
            'کلاس mary-active-menu باید بماند؛ MenuSub به آن وابسته است.'
        );

        $submenu = $this->closestMatch($xpath, $active, 'ancestor::details[1]');
        $this->assertNotNull($submenu, 'آیتم فعال داخل یک زیرمنو است.');
        $this->assertTrue(
            $submenu->hasAttribute('open'),
            'زیرمنویی که آیتم فعال در آن است باید باز رندر شود — این همان چیزی است که به کلاس وابسته است.'
        );
    }

    // ---------------------------------------------------------------------
    // قدم ۳ — لینک پرش و نشانه‌های ناوبری
    // ---------------------------------------------------------------------

    public function test_the_layout_offers_a_skip_link_that_targets_the_main_region(): void
    {
        $xpath = $this->xpath($this->renderAppLayout());

        $skip = $xpath->query('//a[@href="#main-content"]')->item(0);
        $this->assertNotNull($skip, 'لایوت باید لینک پرش داشته باشد؛ بدون آن، کاربر صفحه‌کلید هر بار از کل منو عبور می‌کند.');
        $this->assertNotSame('', trim($skip->textContent), 'لینک پرش باید متن داشته باشد، نه فقط آیکون.');

        $target = $xpath->query('//*[@id="main-content"]')->item(0);
        $this->assertNotNull($target, 'هدف لینک پرش باید در صفحه وجود داشته باشد، وگرنه لینک به جایی می‌رود که نیست.');

        $this->assertSame(
            '-1',
            $target->getAttribute('tabindex'),
            'هدف باید tabindex="-1" داشته باشد وگرنه مرورگر فوکوس را روی آن نمی‌برد و لینک بی‌اثر است.'
        );

        $this->assertSame(
            'main',
            $this->closestMatch($xpath, $target, 'ancestor::main')?->nodeName,
            'هدف لینک پرش باید داخل ناحیهٔ اصلی باشد.'
        );
    }

    public function test_the_skip_link_is_hidden_until_it_takes_focus(): void
    {
        $skip = $this->xpath($this->renderAppLayout())->query('//a[@href="#main-content"]')->item(0);

        $this->assertStringContainsString('sr-only', $skip->getAttribute('class'));
        $this->assertStringContainsString(
            'focus:not-sr-only',
            $skip->getAttribute('class'),
            'لینک پرش باید با فوکوس ظاهر شود؛ sr-only تنها یک عنصر نامرئیِ دیگر است.'
        );
    }

    public function test_the_sidebar_is_a_labelled_navigation_landmark(): void
    {
        $xpath = $this->xpath($this->renderAppLayout());

        $navs = $xpath->query('//nav[@aria-label]');
        $this->assertGreaterThan(0, $navs->length, 'سایدبار باید یک نشانهٔ <nav> با برچسب باشد.');

        $nav = $navs->item(0);
        $this->assertNotSame('', trim($nav->getAttribute('aria-label')), 'نشانهٔ nav باید برچسب داشته باشد؛ نام‌دادنش اجباری است.');

        $this->assertGreaterThan(
            0,
            $xpath->query('.//ul[contains(@class, "menu")]//a', $nav)->length,
            'این <nav> باید همان منوی اصلی را در بر بگیرد.'
        );
    }

    // ---------------------------------------------------------------------
    // قدم ۴ — نام دسترس‌پذیر برای چک‌باکس‌ها و تاگل‌های خام
    // ---------------------------------------------------------------------

    private function renderAppLayout(): string
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);
        Session::put('current_unit_name', 'واحد تست');

        return Blade::render('<x-layouts.app>محتوا</x-layouts.app>');
    }

    private function sematTableHtml(): string
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);
        $this->seedLookupTables();
        Semat::create(['name' => 'عنوان الف']);

        return Livewire::test('kargozini.semat')->html();
    }

    private function sematTableXpath(): DOMXPath
    {
        return $this->xpath($this->sematTableHtml());
    }

    /**
     * n-th `<th>` of the table, counting left to right. Position is the only
     * stable handle: maryUI renders the label plus an icon and puts no column
     * key on the `<th>`.
     */
    private function headerAt(DOMXPath $xpath, int $index): DOMElement
    {
        $headers = $xpath->query('//table/thead/tr/th');

        $this->assertGreaterThan(
            $index,
            $headers->length,
            "جدول {$index}. هدر ندارد — ساختار جدول عوض شده است."
        );

        return $headers->item($index);
    }

    public function test_every_hardware_page_checkbox_has_an_accessible_name(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);
        $this->seedLookupTables();

        // سخت‌افزار باید در اسکوپ همان کاربر باشد وگرنه جدول خالی رندر می‌شود
        // و چک‌باکسِ انتخاب ردیف اصلاً تولید نمی‌شود.
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $this->createHardware(['n_code' => $person->n_code, 'pc_name' => 'PC-NAMED-1']);

        $html = Livewire::test('hardware.index')->html();

        $this->assertEveryCheckboxHasAnAccessibleName($html);
    }

    public function test_every_ticket_inbox_checkbox_has_an_accessible_name(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_assigned_tickets']);
        $this->actingAs($user);
        $this->seedLookupTables();

        // صندوق به‌طور پیش‌فرض روی وضعیت‌های «در انتظار» است، پس تیکت باید
        // `created` باشد و به کاربر نسبت داده شود تا اصلاً رندر شود.
        Ticket::create([
            'ticket_code' => 'T-NAMED-1',
            'subject' => 'تیکت آزمایشی برای نام‌گذاری',
            'content' => 'متن تیکت آزمایشی برای تست نام دسترس‌پذیر.',
            'status' => 'created',
            'priority' => 'normal',
            'unit_id' => $user->person->u_id,
            'user_id' => $user->id,
        ]);

        $html = Livewire::test('tickets.inbox')->html();

        $this->assertEveryCheckboxHasAnAccessibleName($html);
    }

    /**
     * هر `input[type=checkbox]` رندرشده باید نام دسترس‌پذیر داشته باشد: یا
     * `aria-label`، یا `aria-labelledby`، یا `<label for>`، یا یک `<label>`
     * که آن را در بر گرفته. چک‌باکسِ بی‌نام برای صفحه‌خوان فقط «چک‌باکس» است.
     *
     * توجه: عمداً روی *نام* شرط می‌گذاریم نه روی نبودِ `id`. هشت چک‌باکسِ
     * موجود با `<label>` پوشانده شده‌اند و نام دارند؛ افزودن `aria-label`
     * به آن‌ها نامِ قابل‌دیدن را می‌پوشاند و WCAG 2.5.3 را می‌شکند.
     */
    private function assertEveryCheckboxHasAnAccessibleName(string $html): void
    {
        $xpath = $this->xpath($html);

        // `.theme-controller` کنترلر داخلی DaisyUI برای `<x-theme-toggle>` است:
        // `<label for>` دارد ولی فقط آیکون، پس نامش تهی درمی‌آید. از آن ۱۰
        // چک‌باکس خام این ایشو بیرون است (id و label دارد) و درست‌کردنش
        // بازنویسی کامپوننت vendor می‌خواهد — خارج از اسکوپ این ایشو.
        $checkboxes = $xpath->query('//input[@type="checkbox" and not(contains(@class, "theme-controller"))]');

        $this->assertGreaterThan(
            0,
            $checkboxes->length,
            'این صفحه باید دست‌کم یک چک‌باکس رندر کند، وگرنه این تست بی‌اثر است.'
        );

        foreach ($checkboxes as $checkbox) {
            $this->assertNotSame(
                '',
                $this->accessibleName($xpath, $checkbox),
                'چک‌باکسِ value="'.$checkbox->getAttribute('value').'" در این صفحه نام دسترس‌پذیر ندارد.'
            );
        }
    }

    private function accessibleName(DOMXPath $xpath, DOMElement $input): string
    {
        $aria = trim($input->getAttribute('aria-label'));
        if ($aria !== '') {
            return $aria;
        }

        $labelledBy = preg_split(
            '/\s+/',
            trim($input->getAttribute('aria-labelledby')),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $text = '';
        foreach ($labelledBy ?: [] as $id) {
            $text .= $xpath->query('//*[@id="'.htmlspecialchars($id, ENT_QUOTES).'"]')
                ->item(0)?->textContent ?? '';
        }

        if (trim($text) !== '') {
            return trim($text);
        }

        $for = $input->getAttribute('for');
        if ($for !== '') {
            $label = $xpath->query('//label[@for="'.htmlspecialchars($for, ENT_QUOTES).'"]')->item(0);
            if ($label !== null) {
                return trim($label->textContent);
            }
        }

        return trim(
            ($this->closestMatch($xpath, $input, 'ancestor::label[1]')?->textContent ?? '')
            .$input->getAttribute('title')
        );
    }
}
