@props(['wireModel' => 'showHelpModal', 'section' => null])

@php
    $helpSection = $section ?? 'dashboard';
@endphp

<div
    x-data="{ helpSection: '{{ $helpSection }}' }"
    x-init="
        document.addEventListener('help-open', (e) => {
            if (e.detail?.section) { helpSection = e.detail.section; }
            $wire.{{ $wireModel }} = true;
        });
    "
>
    <x-modal wire:model="{{ $wireModel }}" title="راهنما" separator>
        <div class="space-y-6 text-right" dir="rtl">
            <template x-if="helpSection === 'dashboard'">
                <div><x-help-content:dashboard /></div>
            </template>
            <template x-if="helpSection === 'hardware'">
                <div><x-help-content:hardware /></div>
            </template>
            <template x-if="helpSection === 'hardware-import'">
                <div><x-help-content:hardware-import /></div>
            </template>
            <template x-if="helpSection === 'persons-import'">
                <div><x-help-content:persons-import /></div>
            </template>
            <template x-if="helpSection === 'personnel'">
                <div><x-help-content:personnel /></div>
            </template>
            <template x-if="helpSection === 'units'">
                <div><x-help-content:units /></div>
            </template>
            <template x-if="helpSection === 'tickets'">
                <div><x-help-content:tickets /></div>
            </template>
            <template x-if="helpSection === 'todos'">
                <div><x-help-content:todos /></div>
            </template>
            <template x-if="helpSection === 'reports'">
                <div><x-help-content:reports /></div>
            </template>
            <template x-if="helpSection === 'maps'">
                <div><x-help-content:maps /></div>
            </template>
            <template x-if="helpSection === 'settings'">
                <div><x-help-content:settings /></div>
            </template>
            <template x-if="helpSection === 'roles'">
                <div><x-help-content:roles /></div>
            </template>
            <template x-if="helpSection === 'permissions'">
                <div><x-help-content:permissions /></div>
            </template>
            <template x-if="helpSection === 'users'">
                <div><x-help-content:users /></div>
            </template>
            <template x-if="helpSection === 'activity-log'">
                <div><x-help-content:activity-log /></div>
            </template>
            <template x-if="helpSection === 'networks'">
                <div><x-help-content:networks /></div>
            </template>
            <template x-if="helpSection === 'wireless'">
                <div><x-help-content:wireless /></div>
            </template>
            <template x-if="helpSection === 'tools'">
                <div><x-help-content:tools /></div>
            </template>
            <template x-if="helpSection === 'search'">
                <div><x-help-content:search /></div>
            </template>
            <template x-if="helpSection === 'profile'">
                <div><x-help-content:profile /></div>
            </template>

            <div x-show="!['dashboard','hardware','hardware-import','persons-import','personnel','units','tickets','todos','reports','maps','settings','roles','permissions','users','activity-log','networks','wireless','tools','search','profile'].includes(helpSection)"
                 class="text-center py-8 text-base-content/50">
                <x-icon name="o-information-circle" class="w-12 h-12 mx-auto mb-4" />
                <p class="text-lg font-medium">راهنمای این بخش در دسترس نیست</p>
                <p class="text-sm mt-1">برای بخش <span x-text="helpSection"></span> محتوای راهنما تعریف نشده است.</p>
            </div>
        </div>

        <x-slot:actions>
            <x-button wire:click="$set('{{ $wireModel }}', false)" label="بستن" class="btn-primary" />
        </x-slot:actions>
    </x-modal>
</div>