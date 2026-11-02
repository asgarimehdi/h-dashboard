<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * MaryUI MenuSub with CSP-safe submenu state (#1352).
 *
 * WHAT IS AND IS NOT MIRRORED
 * ---------------------------
 * `render()` is a verbatim copy of
 * `Mary\View\Components\MenuSub::render()` with exactly one
 * difference: the `x-data` literal becomes the maryMenuSub factory
 * (the `show` flag and `toggle()` live in
 * `resources/js/mary-components.js`, because the CSP parser rejects
 * method shorthand). `toggle()` still reads the parent sidebar's
 * `collapsed` through the Alpine scope chain, as the literal did.
 *
 * MAINTENANCE
 * -----------
 * Re-sync against the vendor file after any `robsontenorio/mary`
 * upgrade. Registered in AppServiceProvider::boot().
 */
class CspMenuSub extends Component
{
    public string $uuid;

    public function __construct(
        public ?string $id = null,
        public ?string $title = null,
        public ?string $icon = null,
        public ?string $iconClasses = null,
        public bool $open = false,
        public ?bool $hidden = false,
        public ?bool $disabled = false,
    ) {
        $this->uuid = 'mary'.md5(serialize($this)).$id;
    }

    public function render(): View|Closure|string
    {
        if ($this->hidden === true) {
            return '';
        }

        return <<<'BLADE'
                @aware(['horizontal' => false, 'activeBgColor' => 'bg-base-300'])

                @php
                    $submenuActive = Str::contains($slot, 'mary-active-menu');
                @endphp

                @if ($slot->isNotEmpty())
                <li
                @class(['menu-disabled' => $disabled, 'static!' => $horizontal])
                    x-data="maryMenuSub"
                    data-show="@if(($submenuActive || $open) && !$horizontal) true @else false @endif"
                >
                    <details
                        :open="show"
                        @click.stop
                        @if($submenuActive && !$horizontal) open @endif
                        @if($horizontal) @click.outside="show = false" @endif
                    >
                        <summary
                            @click.prevent="toggle()"
                            @class(["hover:text-inherit px-4 py-1.5 my-0.5 text-inherit", $activeBgColor => $submenuActive])
                            @if($horizontal) x-ref="sub" @endif
                        >
                            @if($icon)
                                <x-mary-icon :name="$icon" @class(['inline-flex my-0.5', $iconClasses]) />
                            @endif

                            <span class="mary-hideable whitespace-nowrap truncate">{{ $title }}</span>
                        </summary>

                        <ul @class(["mary-hideable",  "z-10 mt-1" => $horizontal]) @if($horizontal) x-anchor.bottom-start="$refs.sub" @endif>
                            {{ $slot }}
                        </ul>
                    </details>
                </li>
                @endif
                BLADE;
    }
}
