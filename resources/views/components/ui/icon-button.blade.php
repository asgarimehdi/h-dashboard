{{--
    دکمهٔ icon-only با نامِ قابل‌دسترسِ اجباری.

    چرا این کامپوننت هست (#956): آیکن داخلی MaryUI با `aria-hidden="true"` رندر
    می‌شود، پس `<x-button icon=…>` بدون `label=`/`aria-label=` هیچ نامی برای
    صفحه‌خوان نمی‌گذارد. وقتی نام اختیاری باشد، فراموش‌شدنش تدریجاً اتفاق می‌افتد
    و «ویرایش» و «حذف» برای کاربر صفحه‌خوان یکسان می‌شوند. اینجا `name` اجباری
    است، پس یک دکمهٔ بی‌نام دیگر اصلاً قابل نوشتن نیست.

    قرارداد:
      - `name`  (اجباری) → روی `<button>`/`<a>` به‌صورت `aria-label` می‌نشیند.
      - `icon`  (اجباری) → مثل `<x-button icon>`؛ آیکن تزئینی است.
      - `label` (اختیاری) → برچسبِ دیدنی. برای دکمه‌های `responsive` لازم است،
        چون MaryUI آن را زیر `lg` با `hidden lg:block` پنهان می‌کند و آن‌جا
        فقط `aria-label` نام را حمل می‌کند.

    چرا پارامترهای kebab صریحاً اینجا تکرار شده‌اند: `$attributes` فقط «صفت‌های
    HTML» را حمل می‌کند، نه صفت‌های سازندهٔ MaryUI به شکل camelCase. یعنی
    `<x-ui.icon-button tooltip-right="…">` اگر اینجا اعلام نشود، بی‌سروصدا در
    `{{ $attributes }}` گم می‌شود و `data-tip` ساخته نمی‌شود — همان اتفاقی که
    `no-wire-navigate` را هم از کار می‌اندازد. تستِ تفاضلی
    (`IconButtonComponentTest`) رندرِ این کامپوننت را با `<x-button>` خام مقایسه
    می‌کند تا هیچ صفتی در این مسیر گم نشود.
--}}
@props([
    'name',
    'icon',
    'label' => null,
    'iconRight' => null,
    'noWireNavigate' => false,
    'tooltip' => null,
    'tooltipLeft' => null,
    'tooltipRight' => null,
    'tooltipBottom' => null,
    'badge' => null,
    'badgeClasses' => null,
])

<x-button
    :icon="$icon"
    :label="$label"
    :icon-right="$iconRight"
    :no-wire-navigate="$noWireNavigate"
    :tooltip="$tooltip"
    :tooltip-left="$tooltipLeft"
    :tooltip-right="$tooltipRight"
    :tooltip-bottom="$tooltipBottom"
    :badge="$badge"
    :badge-classes="$badgeClasses"
    aria-label="{{ $name }}"
    {{ $attributes }}
>{{ $slot }}</x-button>