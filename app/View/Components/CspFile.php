<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * MaryUI File with CSP-safe upload state (#1352).
 *
 * WHAT IS AND IS NOT MIRRORED
 * ---------------------------
 * `render()` is a verbatim copy of `Mary\View\Components\File::render()`
 * with these differences only: the `@entangle` `x-data` literal
 * becomes `x-data="maryFile"` (state, the `processing` getter, and
 * crop/save/revert methods live in `resources/js/mary-components.js`
 * — the CSP parser rejects method shorthand and getters, and
 * directive-expression entangle evaluates to `undefined`); the
 * `x-on:livewire-upload-progress` directive is gone (the listener is
 * plain JS in the factory `init()`); the `&&` class expressions
 * become ternaries; and the backtick `:style` template literal
 * becomes `progressStyle()`. The native `wire:model` file input,
 * `@this.upload` crop save, and `$refs` crop flow are unchanged in
 * behavior — `crop()`/`save()`/`revert()` are verbatim, reading the
 * server-rendered crop config from data attributes.
 *
 * MAINTENANCE
 * -----------
 * Re-sync against the vendor file after any `robsontenorio/mary`
 * upgrade. Registered in AppServiceProvider::boot().
 */
class CspFile extends Component
{
    public string $uuid;

    /**
     * @param  array<string, mixed>  $cropConfig
     */
    public function __construct(
        public ?string $id = null,
        public ?string $label = null,
        public ?string $hint = null,
        public ?string $hintClass = 'fieldset-label',
        public ?bool $hideProgress = false,
        public ?bool $cropAfterChange = false,
        public ?string $changeText = 'Change',
        public ?string $cropTitleText = 'Crop image',
        public ?string $cropCancelText = 'Cancel',
        public ?string $cropSaveText = 'Crop',
        public ?array $cropConfig = [],
        public ?string $cropMimeType = 'image/png',

        // Popover
        public ?string $popover = null,
        public ?string $popoverIcon = 'o-question-mark-circle',
        public ?string $popoverTriggerClass = '',
        public ?string $popoverContentClass = '',

        // Validations
        public ?string $errorField = null,
        public ?string $errorClass = 'text-error',
        public ?bool $omitError = false,
        public ?bool $firstErrorOnly = false,

    ) {
        $this->uuid = 'mary'.md5(serialize($this)).$id;
    }

    public function modelName(): ?string
    {
        return $this->attributes->whereStartsWith('wire:model')->first();
    }

    public function errorFieldName(): ?string
    {
        return $this->errorField ?? $this->modelName();
    }

    public function cropSetup(): string
    {
        return json_encode(array_merge([
            'autoCropArea' => 1,
            'viewMode' => 1,
            'dragMode' => 'move',
        ], $this->cropConfig));
    }

    public function render(): View|Closure|string
    {
        return <<<'HTML'
                 <div
                    x-data="maryFile"
                    data-model="{{ $attributes->wire('model')->value() }}"
                    data-crop-after-change="{{ json_encode($cropAfterChange) }}"
                    data-crop-setup="{{ $cropSetup() }}"
                    data-crop-mime-type="{{ $cropMimeType }}"


                    {{ $attributes->whereStartsWith('class') }}
                >
                    <fieldset class="fieldset py-0">
                        {{-- STANDARD LABEL --}}
                        @if($label)
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

                        {{-- PROGRESS BAR  --}}
                        @if(! $hideProgress && $slot->isEmpty())
                            <progress
                                x-cloak
                                max="100"
                                :value="progress"
                                :class="processing ? '' : 'hidden'"
                                class="progress h-1 absolute -mt-2 w-56"></progress>
                        @endif

                        {{-- INPUT --}}
                        <input
                            id="{{ $uuid }}"
                            type="file"
                            x-ref="file"
                            @change="refreshImage()"

                            {{
                                $attributes->whereDoesntStartWith('class')->class([
                                    "file-input w-full",
                                    "!file-input-error" => $errorFieldName() && $errors->has($errorFieldName()) && !$omitError,
                                    "hidden" => $slot->isNotEmpty()
                                ])
                            }}
                        />

                        @if ($slot->isNotEmpty())
                            <!-- PREVIEW AREA -->
                            <div x-ref="preview" class="relative flex">
                                <div
                                    wire:ignore
                                    @click="change()"
                                    :class="processing ? 'opacity-50 pointer-events-none' : ''"
                                    class="cursor-pointer hover:scale-105 transition-all tooltip"
                                    data-tip="{{ $changeText }}"
                                >
                                    {{ $slot }}
                                </div>
                                <!-- PROGRESS -->
                                <div
                                    x-cloak
                                    :style="progressStyle()"
                                    :class="processing ? '' : 'hidden'"
                                    class="radial-progress text-success absolute top-5 start-5 bg-neutral"
                                    role="progressbar"
                                ></div>
                            </div>

                            <!-- CROP MODAL -->
                            <div @click.prevent="" x-ref="crop" wire:ignore>
                                <x-mary-modal id="maryCrop{{ $uuid }}" x-ref="maryCrop" :title="$cropTitleText" separator class="backdrop-blur-sm" persistent @keydown.window.esc.prevent="" without-trap-focus>
                                    <img src="" />
                                    <x-slot:actions>
                                        <x-mary-button :label="$cropCancelText" @click="close()" />
                                        <x-mary-button :label="$cropSaveText" class="btn-primary" @click="save()" ::disabled="processing" />
                                    </x-slot:actions>
                                </x-mary-modal>
                            </div>
                        @endif

                        {{-- ERROR --}}
                        @if(!$omitError && $errors->has($errorFieldName()))
                            @foreach($errors->get($errorFieldName()) as $message)
                                @foreach(Arr::wrap($message) as $line)
                                    <div class="{{ $errorClass }}" x-classes="text-error">{{ $line }}</div>
                                    @break($firstErrorOnly)
                                @endforeach
                                @break($firstErrorOnly)
                            @endforeach
                        @endif

                        {{-- MULTIPLE --}}
                        @error($modelName().'.*')
                            <div class="text-error" x-classes="text-error">{{ $message }}</div>
                        @enderror

                        {{-- HINT --}}
                        @if($hint)
                            <div class="{{ $hintClass }}" x-classes="fieldset-label">{{ $hint }}</div>
                        @endif
                    </fieldset>
                </div>
            HTML;
    }
}
