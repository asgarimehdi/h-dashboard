{{--<x-dropdown class="btn-sm" label="Theme" title="Theme" icon="o-swatch" >--}}
{{--    <x-slot:trigger>--}}
{{--        <x-button icon="o-swatch" class="btn-circle btn-outline" />--}}
{{--    </x-slot:trigger>--}}
{{--    <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm  btn-ghost justify-start" aria-label="Luxury" value="luxury" />--}}
{{--    <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm  btn-ghost justify-start" aria-label="Valentine" value="valentine" />--}}
{{--    <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm  btn-ghost justify-start" aria-label="Cupcake" value="cupcake" />--}}
{{--    <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm  btn-ghost justify-start" aria-label="Aqua" value="aqua" />--}}
{{--    <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm  btn-ghost justify-start" aria-label="Dark" value="dark" />--}}
{{--    <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm  btn-ghost justify-start" aria-label="Light" value="light" />--}}
{{--</x-dropdown>--}}
@php
    // #1224: the toggle cycles between the user's two saved poles. Guests and
    // users without settings keep the pre-issue hard-coded fantasy/dark pair.
    $settings = auth()->user()?->settings ?? [];
    $lightTheme = \App\Support\ThemeFamilies::poleOrDefault($settings['light_theme'] ?? null, 'light');
    $darkTheme = \App\Support\ThemeFamilies::poleOrDefault($settings['dark_theme'] ?? null, 'dark');
@endphp
<div class="flex items-center gap-1">
    <livewire:notifications.bell />
    <x-csp-theme-toggle darkTheme="{{ $darkTheme }}" lightTheme="{{ $lightTheme }}" />
</div>
