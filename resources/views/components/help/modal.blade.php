@props(['wireModel' => 'showHelpModal', 'section' => null])

@php
    $helpSection = $section ?? 'dashboard';
@endphp

<x-modal wire:model="{{ $wireModel }}" title="راهنما" separator>
    <div class="space-y-6 text-right" dir="rtl">
        @switch($helpSection)
            @case('dashboard')
                <x-help-content:dashboard />
                @break
            @case('hardware')
                <x-help-content:hardware />
                @break
            @case('hardware-import')
                <x-help-content:hardware-import />
                @break
            @case('hardware-ai')
                <x-help-content:hardware-ai />
                @break
            @case('personnel')
                <x-help-content:personnel />
                @break
            @case('units')
                <x-help-content:units />
                @break
            @case('tickets')
                <x-help-content:tickets />
                @break
            @case('todos')
                <x-help-content:todos />
                @break
            @case('reports')
                <x-help-content:reports />
                @break
            @case('maps')
                <x-help-content:maps />
                @break
            @case('settings')
                <x-help-content:settings />
                @break
            @case('roles')
                <x-help-content:roles />
                @break
            @case('permissions')
                <x-help-content:permissions />
                @break
            @case('users')
                <x-help-content:users />
                @break
            @case('activity-log')
                <x-help-content:activity-log />
                @break
            @case('networks')
                <x-help-content:networks />
                @break
            @case('wireless')
                <x-help-content:wireless />
                @break
            @default
                <div class="text-center py-8 text-base-content/50">
                    <x-icon name="o-information-circle" class="w-12 h-12 mx-auto mb-4" />
                    <p class="text-lg font-medium">راهنمای این بخش در دسترس نیست</p>
                    <p class="text-sm mt-1">برای بخش {{ $helpSection }} محتوای راهنما تعریف نشده است.</p>
                </div>
        @endswitch
    </div>
    
    <x-slot:actions>
        <x-button wire:click="$set('{{ $wireModel }}', false)" label="بستن" class="btn-primary" />
    </x-slot:actions>
</x-modal>