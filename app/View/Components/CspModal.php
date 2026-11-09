<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * MaryUI Modal with CSP-safe open state (#1352).
 *
 * WHAT IS AND IS NOT MIRRORED
 * ---------------------------
 * `render()` is a verbatim copy of `Mary\View\Components\Modal::render()`
 * with exactly four differences: the `@entangle(...).live` `x-data`
 * literal becomes `x-data="maryModal"` (the open state is a plain
 * boolean synced from `$wire` after every Livewire morph in
 * `resources/js/mary-components.js`, because directive-expression
 * entangle evaluates to `undefined` under the CSP build — Step-1
 * entangle probe); the `$watch` `x-init` is gone (the watch lives in
 * the factory `init()`); the `:class` object literal becomes a
 * ternary (object literals are outside the proven directive subset);
 * and the three `$wire.model = false` close directives (close
 * button, backdrop button, Escape) are gone — a `$wire` member
 * assignment evaluated through the CSP parser is a silent no-op
 * (proven by probe), so the close writes are plain-JS listeners in
 * the factory. `x-trap` and the `:open` binding are unchanged.
 *
 * MAINTENANCE
 * -----------
 * Re-sync against the vendor file after any `robsontenorio/mary`
 * upgrade. Registered in AppServiceProvider::boot().
 */
class CspModal extends Component
{
    public function __construct(
        public ?string $id = '',
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $boxClass = null,
        public ?bool $separator = false,
        public ?bool $persistent = false,
        public ?bool $withoutTrapFocus = false,

        // Slots
        public ?string $actions = null
    ) {
        //
    }

    public function render(): View|Closure|string
    {
        return <<<'HTML'
                <dialog
                    {{ $attributes->except('wire:model')->class(["modal"]) }}

                    @if($id)
                        id="{{ $id }}"
                    @else
                        x-data="maryModal"
                        data-model="{{ $attributes->wire('model')->value() }}"
                        data-persistent="{{ $persistent ? 'true' : 'false' }}"
                        :class="open ? 'modal-open !animate-none' : ''"
                        :open="open"
                    @endif

                    @if(!$withoutTrapFocus)
                        x-trap="open" x-bind:inert="!open"
                    @endif
                >
                    <div class="modal-box {{ $boxClass }}">
                        @if(!$persistent)
                            <form method="dialog" tabindex="-1">
                                @if ($id)
                                    <x-mary-button class="btn-circle btn-sm btn-ghost absolute end-2 top-2 z-[999]" icon="o-x-mark" type="submit" tabindex="-1" />
                                @else
                                    <x-mary-button class="btn-circle btn-sm btn-ghost absolute end-2 top-2 z-[999]" icon="o-x-mark" tabindex="-1" />
                                @endif
                            </form>
                        @endif

                        @if($title)
                            <x-mary-header :title="$title" :subtitle="$subtitle" size="text-xl" :separator="$separator" class="!mb-5" />
                        @endif

                        <div>
                            {{ $slot }}
                        </div>

                        @if($separator && $actions)
                            <hr class="border-t-[length:var(--border)] border-base-content/10 mt-5" />
                        @endif

                        @if($actions)
                            <div class="modal-action">
                                {{ $actions }}
                            </div>
                        @endif
                    </div>

                    @if(!$persistent)
                        <form class="modal-backdrop" method="dialog">
                            @if ($id)
                                <button type="submit">close</button>
                            @else
                                <button type="button">close</button>
                            @endif
                        </form>
                    @endif
                </dialog>
                HTML;
    }
}
