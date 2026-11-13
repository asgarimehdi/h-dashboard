{{-- #1281: app-owned copy of MaryUI 2.9.11's ThemeToggle — markup identical to
     vendor/robsontenorio/mary/src/View/Components/ThemeToggle.php, with a
     per-request CSP nonce on the inline <script> (nonce attributes cannot
     cross a vendor class-component heredoc). The `$uuid` constant changes from
     Maryland's serialize()-based md5 to uniqid(): both are unique per rendered
     instance, which is all the <label for>/<input id> pair needs.
     Upgrade condition: a Mary nonce pass-through — delete and repoint. --}}
@props(['id' => null, 'light' => 'Light', 'dark' => 'Dark', 'lightTheme' => 'light',
    'darkTheme' => 'dark', 'lightClass' => 'light', 'darkClass' => 'dark', 'withLabel' => false])
@php
    $uuid = 'mary'.md5(uniqid('', true)).($id ?? '');
@endphp
<div>
    <label
        for="{{ $uuid }}"
        x-data="themeToggle"
        data-light-theme="{{ $lightTheme }}"
        data-dark-theme="{{ $darkTheme }}"
        data-light-class="{{ $lightClass }}"
        data-dark-class="{{ $darkClass }}"
        @mary-toggle-theme.window="toggle()"
        {{ $attributes->class("swap swap-rotate rounded-full hover:text-primary hover:bg-primary/10 transition-colors") }}
    >
        <input id="{{ $uuid }}" type="checkbox" class="theme-controller opacity-0" @click="toggle()" :value="theme" />
        <x-mary-icon x-ref="sun" name="o-sun" class="swap-on" />
        <x-mary-icon x-ref="moon" name="o-moon" class="swap-off"  />
    </label>
</div>
<script nonce="{{ Vite::cspNonce() }}">
    document.documentElement.setAttribute("data-theme", localStorage.getItem("mary-theme")?.replaceAll("\"", ""))
    document.documentElement.setAttribute("class", localStorage.getItem("mary-class")?.replaceAll("\"", ""))
</script>
