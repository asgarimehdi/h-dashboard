@props(['section' => 'default', 'wireModel' => 'showHelpModal'])

<button
    type="button"
    wire:click="$wire.{{ $wireModel }} = true; $dispatch('help-open', { section: '{{ $section }}' })"
    class="btn-ghost btn-sm btn-circle text-base-content/50 hover:text-primary hover:bg-primary/10 transition-colors"
    title="راهنما"
    aria-label="نمایش راهنمای {{ $section }}"
>
    <x-icon name="o-question-mark-circle" class="w-5 h-5" />
</button>