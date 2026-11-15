@props(['wireModel' => 'showHelpModal', 'section' => null])

@php
    $helpSection = $section ?? 'dashboard';
    // This is an anonymous Blade component, so it does not inherit the Livewire
    // view's data. Livewire shares the rendering instance as $__livewire, which
    // lets us read the open flag without touching the modal's consumers.
    $helpModalOpen = data_get($__livewire ?? null, $wireModel, false);
    // #1535: one registry. The section list lives in config/help.php; the same
    // list is handed to Alpine below so `noHelpContent()` cannot drift from it.
    $helpSections = config('help.sections');
@endphp

<div
    x-data="helpModal"
    data-section="{{ $helpSection }}"
    data-sections="{{ implode(',', $helpSections) }}"
>
    <x-modal wire:model="{{ $wireModel }}" title="راهنما" separator>
        @if ($helpModalOpen)
            <div class="space-y-6 text-right" dir="rtl">
                @foreach ($helpSections as $helpSectionName)
                    <template x-if="helpSection === '{{ $helpSectionName }}'">
                        <div>@include("components.help.content.{$helpSectionName}")</div>
                    </template>
                @endforeach

                <div x-show="noHelpContent()"
                     class="text-center py-8 text-base-content/50">
                    <x-icon name="o-information-circle" class="w-12 h-12 mx-auto mb-4" />
                    <p class="text-lg font-medium">راهنمای این بخش در دسترس نیست</p>
                    <p class="text-sm mt-1">برای بخش <span x-text="helpSection"></span> محتوای راهنما تعریف نشده است.</p>
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-button wire:click="$set('{{ $wireModel }}', false)" label="بستن" class="btn-primary" />
        </x-slot:actions>
    </x-modal>
</div>