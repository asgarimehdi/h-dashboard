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

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6" dir="rtl">
        <div class="lg:col-span-3">
            <x-card shadow>
                <x-input
                    placeholder="جستجوی واحد..."
                    wire:model.live.debounce.300ms="search"
                    icon="o-magnifying-glass"
                    clearable
                    class="w-full mb-4" />
                <div class="tree-container text-right" dir="rtl">
                    @foreach ($rootUnits as $unit)
                        @include('livewire.hr.org-node', ['unit' => $unit, 'level' => 0, 'isLast' => $loop->last])
                    @endforeach

                    @if($rootUnits->isEmpty())
                        <div class="text-center p-10 text-gray-400">واحدی یافت نشد.</div>
                    @endif
                </div>
            </x-card>
        </div>

        {{-- جزئیات واحد انتخاب شده --}}
        <div class="lg:col-span-1 sticky top-4">
            @if($selectedUnit)
            <x-card shadow>
                <h3 class="font-bold mb-3">{{ $selectedUnit->name }}</h3>
                <div class="space-y-2 text-sm">
                    <div><span class="font-bold">نوع:</span> {{ $selectedUnit->unitType?->name ?? '---' }}</div>
                    <div><span class="font-bold">والد:</span> {{ $selectedUnit->parent?->name ?? '---' }}</div>
                    <div><span class="font-bold">پرسنل مستقیم:</span> {{ $selectedPersonnelTotal }} نفر <span class="text-xs opacity-60">(زیرمجموعه: {{ $descendantPersonnelTotal }} نفر)</span></div>
                    <div><span class="font-bold">کاربران مستقیم:</span> {{ $directUserCount }} نفر <span class="text-xs opacity-60">(زیرمجموعه: {{ $descendantUserCount }} نفر)</span></div>
                </div>
                <div class="mt-4">
                    <h4 class="font-bold text-xs mb-2">پرسنل این واحد (۲۰ نفر اول):</h4>
                    @forelse($selectedPersonnel as $p)
                    <div class="flex items-center gap-2 p-2 bg-base-200/50 rounded mb-1">
                        <x-icon name="o-user" class="w-4 h-4 {{ $p->user ? 'text-success' : 'text-error' }}" />
                        <span class="text-xs">{{ $p->f_name }} {{ $p->l_name }}</span>
                        <span class="badge badge-xs badge-ghost">{{ $p->semat?->name ?? '---' }}</span>
                    </div>
                    @empty
                    <p class="text-xs opacity-50">پرسنلی ندارد</p>
                    @endforelse
                </div>
            </x-card>
            @else
            <x-card shadow>
                <p class="text-sm opacity-50 text-center py-8">یک واحد را انتخاب کنید</p>
            </x-card>
            @endif
        </div>
    </div>

    <style>
        .tree-line-branch {
            position: absolute;
            right: -20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #040505;
        }
        .tree-line-leaf {
            position: absolute;
            right: -20px;
            top: 24px;
            width: 20px;
            height: 2px;
            background-color: #040505;
        }
        .tree-node-dot {
            width: 8px;
            height: 8px;
            background-color: #040505;
            border-radius: 50%;
            position: absolute;
            right: -23px;
            top: 21px;
        }
    </style>
</div>
