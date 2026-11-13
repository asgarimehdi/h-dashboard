<?php

namespace Tests\Feature\Accessibility;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * قرارداد DOM مودالِ CSP (#1468).
 *
 * لیسِنِر بسته‌شدن در `resources/js/mary-components.js` به شکلِ درختِ
 * رندر‌شدهٔ `CspModal` تکیه دارد: دکمهٔ × داخلِ `.modal-box > form[method="dialog"]`
 * است، نه فرزندِ مستقیمِ `<dialog>`. گاردِ قدیمی (`form.parentElement !== dialog`)
 * دقیقاً همین فرم را رد می‌کرد و × هیچ کاری نمی‌کرد.
 *
 * اگر heredoc دوباره دستکاری شود (ارتقای MaryUI یا ویرایشِ CspModal)، این تست
 * پیش از E2E می‌شکند و قراردادِ موردِ اتکای سلکتور را loud نگه می‌دارد.
 */
class CspModalContractTest extends TestCase
{
    public function test_the_close_button_lives_in_a_dialog_form_inside_the_modal_box(): void
    {
        $xpath = $this->xpathFor('<x-modal wire:model="x">محتوا</x-modal>');

        $dialog = $xpath->query('//dialog[contains(concat(" ", normalize-space(@class), " "), " modal ")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $dialog, 'مودال باید <dialog class="modal"> رندر کند.');

        // سلکتور JS: `:scope > .modal-box > form[method="dialog"]`.
        $closeForms = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " modal-box ")]/form[@method="dialog"]', $dialog);
        $this->assertSame(1, $closeForms->length, 'فرمِ × باید فرزندِ مستقیمِ .modal-box باشد.');

        $closeButton = $xpath->query('./button', $closeForms->item(0))->item(0);
        $this->assertInstanceOf(DOMElement::class, $closeButton, 'فرمِ × باید یک دکمه داشته باشد.');
        // MaryUI پیش‌فرض `type="button"` می‌گذارد؛ submit بودنِ آن `open` را
        // true نگه می‌دارد و مودال دیگر باز نمی‌شود.
        $this->assertSame('button', $closeButton->getAttribute('type'));

        // فرمِ backdrop فرزندِ مستقیمِ dialog است (سلکتورِ `:scope >` عمداً
        // فقط همین را در سطحِ اول پیدا می‌کند).
        $backdropForms = $xpath->query('./form[@method="dialog" and contains(concat(" ", normalize-space(@class), " "), " modal-backdrop ")]', $dialog);
        $this->assertSame(1, $backdropForms->length, 'فرمِ backdrop باید فرزندِ مستقیمِ dialog باشد.');

        // ریشهٔ باگ: تنها فرمِ dialog در سطحِ اول، backdrop است؛ فرمِ ×
        // هرگز فرزندِ مستقیمِ dialog نیست.
        $this->assertSame(1, $xpath->query('./form[@method="dialog"]', $dialog)->length);
    }

    public function test_a_persistent_modal_renders_no_close_or_backdrop_form(): void
    {
        $xpath = $this->xpathFor('<x-modal wire:model="x" persistent>محتوا</x-modal>');

        $this->assertSame(
            0,
            $xpath->query('//dialog//form[@method="dialog"]')->length,
            'مودال persistent نباید فرمِ × یا backdrop داشته باشد.'
        );
    }

    private function xpathFor(string $template): DOMXPath
    {
        $html = Blade::render($template);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
