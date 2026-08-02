<div>
    <x-header title="چارت سازمانی" separator progress-indicator>
        <x-slot:actions>
            <x-button icon="o-arrows-pointing-in" label="جمع کردن" wire:click="collapseAll" class="btn-ghost btn-sm" />
            <x-button icon="o-arrows-pointing-out" label="باز کردن همه" wire:click="expandAll" class="btn-ghost btn-sm" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="mb-4">
        <x-input wire:model.live.debounce.300ms="search" placeholder="جستجوی واحد..." icon="o-magnifying-glass" />
    </div>

    <div class="space-y-2" dir="rtl">
        @foreach ($tree as $node)
            <x-livewire.hr.org-node :node="$node" :expanded="$expanded" :search="$search" />
        @endforeach
    </div>
</div>
