@props(['section' => 'default', 'wireModel' => 'showHelpModal'])

{{-- #1224: the icon colour was text-base-content/50, measured 3.03–3.36:1 on the
     light poles and 1.69:1 on coffee's glass card. /80 clears 4.5:1 on all
     seven enabled themes (worst: coffee 4.97:1). --}}
<button
    type="button"
    wire:click="$wire.{{ $wireModel }} = true; $dispatch('help-open', { section: '{{ $section }}' })"
    class="btn-ghost btn-sm btn-circle text-base-content/80 hover:text-primary hover:bg-primary/10 transition-colors"
    title="راهنما"
    aria-label="نمایش راهنمای {{ $section }}"
>
    <x-icon name="o-question-mark-circle" class="w-5 h-5" />
</button>