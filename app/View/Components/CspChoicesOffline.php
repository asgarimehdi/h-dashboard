<?php

namespace App\View\Components;

use Closure;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * MaryUI ChoicesOffline with CSP-safe selection state (#1352).
 *
 * WHAT IS AND IS NOT MIRRORED
 * ---------------------------
 * `render()` is a verbatim copy of
 * `Mary\View\Components\ChoicesOffline::render()` with these
 * differences only: the outer wrapper's `{focused, selection}`
 * literal is gone (both live in the single maryChoices factory —
 * nothing outside the inner element reads them); the inner `x-data`
 * literal becomes `x-data="maryChoices"` (state, getters, and methods
 * live in `resources/js/mary-components.js` — the CSP parser rejects
 * method shorthand and getters, and directive-expression entangle
 * evaluates to `undefined`); the options array rides in an inert
 * JSON block (a data attribute truncates, see the tree-picker
 * lesson); the `||`/`&&` class and required expressions become
 * method calls; and the `x-html` selection slot becomes a plain-JS
 * innerHTML copy from the hidden per-option source spans
 * (`renderSelectionSlots()`), because `x-html` is banned in the CSP
 * build. `@click.outside`, `@keyup`, `@keydown`, `x-model`, and the
 * `:id` binding are unchanged.
 *
 * MAINTENANCE
 * -----------
 * Re-sync against the vendor file after any `robsontenorio/mary`
 * upgrade. Registered in AppServiceProvider::boot().
 */
class CspChoicesOffline extends Component
{
    public string $uuid;

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $options
     */
    public function __construct(
        public ?string $id = null,
        public ?string $label = null,
        public ?string $hint = null,
        public ?string $hintClass = 'fieldset-label',
        public ?string $icon = null,
        public ?string $iconRight = null,
        public ?bool $inline = false,
        public ?bool $clearable = false,
        public ?string $prefix = null,
        public ?string $suffix = null,

        public ?bool $searchable = false,
        public ?bool $single = false,
        public ?bool $compact = false,
        public ?string $compactText = 'selected',
        public ?bool $allowAll = false,
        public ?string $debounce = '250ms',
        public ?int $minChars = 0,
        public ?string $allowAllText = 'Select all',
        public ?string $removeAllText = 'Remove all',
        public ?string $optionValue = 'id',
        public ?string $optionLabel = 'name',
        public ?string $optionSubLabel = '',
        public ?string $optionAvatar = 'avatar',
        public ?bool $valuesAsString = false,
        public ?bool $escapeValues = false,
        public ?string $height = 'max-h-64',
        public Collection|array $options = new Collection,
        public ?string $noResultText = 'No results found.',

        // Popover
        public ?string $popover = null,
        public ?string $popoverIcon = 'o-question-mark-circle',
        public ?string $popoverTriggerClass = '',
        public ?string $popoverContentClass = '',

        // Validations
        public ?string $errorField = null,
        public ?string $errorClass = 'text-error label-text-alt p-1',
        public ?bool $omitError = false,
        public ?bool $firstErrorOnly = false,

        // Slots
        public mixed $item = null,
        public mixed $selection = null,
        public mixed $prepend = null,
        public mixed $append = null
    ) {
        $this->uuid = 'mary'.md5(serialize($this)).$id;

        if (($this->allowAll || $this->compact) && ($this->single || $this->searchable)) {
            throw new Exception('`allow-all` and `compact` does not work combined with `single` or `searchable`.');
        }
    }

    public function modelName(): ?string
    {
        return $this->attributes->whereStartsWith('wire:model')->first();
    }

    public function errorFieldName(): ?string
    {
        return $this->errorField ?? $this->modelName();
    }

    public function isReadonly(): bool
    {
        return $this->attributes->has('readonly') && $this->attributes->get('readonly') == true;
    }

    public function isDisabled(): bool
    {
        return $this->attributes->has('disabled') && $this->attributes->get('disabled') == true;
    }

    public function isRequired(): bool
    {
        return $this->attributes->has('required') && $this->attributes->get('required') == true;
    }

    /**
     * @param  mixed  $option
     */
    public function getOptionValue($option): mixed
    {
        $value = data_get($option, $this->optionValue);

        if ($this->valuesAsString) {
            return "'$value'";
        }

        if ($this->escapeValues) {
            $value = addslashes($value);

            return "'$value'";
        }

        return is_numeric($value) && ! str($value)->startsWith('0') ? $value : "'$value'";
    }

    public function render(): View|Closure|string
    {
        return <<<'HTML'
                <div>
                    <div
                        @click.outside = "clear()"
                        @keyup.esc = "clear()"

                        x-data="maryChoices"
                        data-model="{{ $attributes->wire('model') ? $attributes->wire('model')->value() : '' }}"
                        data-choices-id="{{ $uuid }}"
                        data-single="{{ json_encode($single) }}"
                        data-searchable="{{ json_encode($searchable) }}"
                        data-readonly="{{ json_encode($isReadonly()) }}"
                        data-disabled="{{ json_encode($isDisabled()) }}"
                        data-required="{{ json_encode($isRequired()) }}"
                        data-min-chars="{{ $minChars }}"
                        data-option-value="{{ $optionValue }}"
                        data-option-label="{{ $optionLabel }}"

                        @keydown.up="focusPrevious()"
                        @keydown.down="focusNext()"
                    >
                        <script type="application/json" class="choices-options-data">@json($options)</script>
                        <fieldset class="fieldset py-0">
                            {{-- STANDARD LABEL --}}
                            @if($label && !$inline)
                                <legend class="fieldset-legend mb-0.5">
                                    {{ $label }}

                                    @if($attributes->get('required'))
                                        <span class="text-error">*</span>
                                    @endif

                                    {{-- INPUT POPOVER --}}
                                    @if($popover)
                                        <x-mary-popover offset="5" position="top-start">
                                            <x-slot:trigger class="{{ $popoverTriggerClass }}">
                                                <x-mary-icon :name="$popoverIcon" class="w-4 h-4 opacity-40 mb-0.5" />
                                            </x-slot:trigger>
                                            <x-slot:content class="{{ $popoverContentClass }}">
                                                {{ $popover }}
                                            </x-slot:content>
                                        </x-mary-popover>
                                    @endif
                                </legend>
                            @endif

                            <label @class(["floating-label" => $label && $inline])>
                                {{-- FLOATING LABEL--}}
                                @if ($label && $inline)
                                    <span class="font-semibold">
                                        {{ $label }}

                                        @if($attributes->get('required'))
                                            <span class="text-error">*</span>
                                        @endif
                                    </span>
                                @endif

                                <div @class(["w-full", "join" => $prepend || $append])>
                                    {{-- PREPEND --}}
                                    @if($prepend)
                                        {{ $prepend }}
                                    @endif

                                    {{-- THE LABEL THAT HOLDS THE INPUT --}}
                                    <label
                                        x-ref="container"

                                        @if($isDisabled())
                                            disabled
                                        @endif

                                        @if(!$isDisabled() && !$isReadonly())
                                            @click="focus()"
                                        @endif

                                        {{
                                            $attributes->whereStartsWith('class')->class([
                                                "select w-full min-h-[var(--size)] h-auto ps-2.5",
                                                "join-item" => $prepend || $append,
                                                "border-dashed" => $attributes->has("readonly") && $attributes->get("readonly") == true,
                                                "!select-error" => $errorFieldName() && $errors->has($errorFieldName()) && !$omitError
                                            ])
                                        }}
                                    >
                                        {{-- PREFIX --}}
                                        @if($prefix)
                                            <span class="label">{{ $prefix }}</span>
                                        @endif

                                        {{-- ICON LEFT --}}
                                        @if($icon)
                                            <x-mary-icon :name="$icon" class="pointer-events-none w-4 h-4 opacity-40" />
                                        @endif

                                        <div class="w-full py-0.5 min-h-3 content-center text-wrap">

                                            {{-- SELECTED OPTIONS --}}
                                            <span wire:key="selected-options-{{ $uuid }}">
                                                @if($compact)
                                                    <div class="badge badge-soft">
                                                        <span class="font-black" x-text="selectedOptions.length"></span> {{ $compactText }}
                                                    </div>
                                                @else
                                                    <template x-for="(option, index) in selectedOptions" :key="index">
                                                        <span class="mary-choices-element cursor-pointer badge badge-soft m-0.5 !inline-block !h-auto">
                                                            {{-- SELECTION SLOT --}}
                                                            @if($selection)
                                                                <span data-selection-slot :data-option-value="option.{{ $optionValue }}"></span>
                                                            @else
                                                                <span x-text="option?.{{ $optionLabel }}"></span>
                                                            @endif

                                                            @if(!$isDisabled() && !$isReadonly())
                                                                <x-mary-icon @click="toggle(option.{{ $optionValue }})" x-show="canRemove()" name="o-x-mark" class="w-4 h-4 hover:text-error" />
                                                            @endif
                                                        </span>
                                                    </template>
                                                @endif
                                            </span>

                                            {{-- PLACEHOLDER --}}
                                            <span :class="placeholderClass()"  class="text-base-content/40">
                                                {{ $attributes->get('placeholder') }}
                                            </span>

                                            {{-- INPUT SEARCH --}}
                                            <input
                                                :id="id"
                                                x-ref="searchInput"
                                                x-model="search"
                                                @keyup="lookup()"
                                                @input="focus(); resize();"
                                                @keydown.arrow-down.prevent="focus()"
                                                :required="searchRequired()"
                                                class="w-1 !inline-block outline-hidden"

                                                {{ $attributes->whereStartsWith('@') }}

                                                @if($isReadonly() || $isDisabled() || ! $searchable)
                                                    readonly
                                                @else
                                                    @focus="focus()"
                                                @endif

                                                @if($isDisabled())
                                                    disabled
                                                 @endif
                                             />
                                        </div>

                                        {{-- CLEAR ICON  --}}
                                        @if($clearable && !$isReadonly() && !$isDisabled())
                                            <x-mary-icon @click="reset()" x-show="!isSelectionEmpty" name="o-x-mark" class="cursor-pointer w-4 h-4 opacity-40"/>
                                        @endif

                                        {{-- ICON RIGHT --}}
                                        @if($iconRight)
                                            <x-mary-icon :name="$iconRight" class="pointer-events-none w-4 h-4 opacity-40" />
                                        @endif

                                        {{-- SUFFIX --}}
                                        @if($suffix)
                                            <span class="label">{{ $suffix }}</span>
                                        @endif

                                    </label>

                                    {{-- APPEND --}}
                                    @if($append)
                                        {{ $append }}
                                    @endif
                                </div>
                            </label>

                                {{-- ERROR --}}
                                @if(!$omitError && $errors->has($errorFieldName()))
                                    @foreach($errors->get($errorFieldName()) as $message)
                                        @foreach(Arr::wrap($message) as $line)
                                            <div class="{{ $errorClass }}" x-class="text-error">{{ $line }}</div>
                                            @break($firstErrorOnly)
                                        @endforeach
                                        @break($firstErrorOnly)
                                    @endforeach
                                @endif

                                {{-- HINT --}}
                                @if($hint)
                                    <div class="{{ $hintClass }}" x-classes="fieldset-label">{{ $hint }}</div>
                                @endif
                        </fieldset>

                        {{-- OPTIONS LIST --}}
                        <div x-cloak x-show="focused" class="relative" wire:key="options-list-main-{{ $uuid }}" >
                                <div
                                    wire:key="options-list-{{ $uuid }}"
                                    class="{{ $height }} w-full absolute z-10 shadow-xl bg-base-100 border border-base-content/10 rounded-lg cursor-pointer overflow-y-auto"
                                    x-anchor.bottom-start="$refs.container"
                                >

                                   {{-- SELECT ALL --}}
                                   @if($allowAll)
                                       <div
                                            wire:key="allow-all-{{ rand() }}"
                                            class="font-bold border border-s-4 border-b-base-200 hover:bg-base-200"
                                       >
                                            <div x-show="!isAllSelected" @click="selectAll()" class="p-3 underline decoration-wavy decoration-info">{{ $allowAllText }}</div>
                                            <div x-show="isAllSelected" @click="reset()" class="p-3 underline decoration-wavy decoration-error">{{ $removeAllText }}</div>
                                       </div>
                                   @endif

                                    {{-- NO RESULTS --}}
                                    <div
                                        x-show="noResults"
                                        wire:key="no-results-{{ rand() }}"
                                        class="p-3 decoration-wavy decoration-warning underline font-bold border border-s-4 border-s-warning border-b-base-200"
                                    >
                                        {{ $noResultText }}
                                    </div>

                                    <div x-ref="choicesOptions">
                                        @foreach($options as $option)
                                            <div
                                                id="option-{{ $uuid }}-{{ data_get($option, $optionValue) }}"
                                                wire:key="option-{{ data_get($option, $optionValue) }}"
                                                @click="toggle({{ $getOptionValue($option) }}, true)"
                                                @keydown.enter="toggle({{ $getOptionValue($option) }}, true)"
                                                :class="isActive({{ $getOptionValue($option) }}) ? 'border-s-4 border-s-base-content' : ''"
                                                search-value="{{ data_get($option, $optionLabel) }}"
                                                class="border-s-4 border-base-content/10 focus:bg-base-200 focus:outline-none"
                                                tabindex="0"
                                            >
                                                {{-- ITEM SLOT --}}
                                                @if($item)
                                                    {{ $item($option) }}
                                                @else
                                                    <x-mary-list-item :item="$option" :value="$optionLabel" :sub-value="$optionSubLabel" :avatar="$optionAvatar" />
                                                @endif

                                                {{-- SELECTION SLOT --}}
                                                @if($selection)
                                                    <span id="selection-{{ $uuid }}-{{ data_get($option, $optionValue) }}" class="hidden">
                                                        {{ $selection($option) }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                    </div>
                </div>
            HTML;
    }
}
