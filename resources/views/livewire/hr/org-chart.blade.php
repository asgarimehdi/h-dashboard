<div>
    <x-header title="چارت سازمانی" separator progress-indicator>
        <x-slot:actions>
            <x-button icon="o-arrows-pointing-in" label="جمع کردن" wire:click="collapseAll" class="btn-ghost btn-sm" />
            <x-button icon="o-arrows-pointing-out" label="باز کردن همه" wire:click="expandAll" class="btn-ghost btn-sm" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="mb-4">
        <x-input wire:model.live.debounce.300ms="search" placeholder="جستجوی واحد..." icon="o-magnifying-glass" clearable />
    </div>

    <div class="space-y-2" dir="rtl">
        @foreach ($rootUnits as $unit)
            @include('livewire.hr.org-node', ['unit' => $unit, 'level' => 0])
        @endforeach

        @if($rootUnits->isEmpty())
            <div class="text-center p-10 text-gray-400">واحدی یافت نشد.</div>
        @endif
    </div>
</div>
