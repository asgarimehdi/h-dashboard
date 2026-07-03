<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\{Unit, User};
use App\Services\AccessService;

new #[Layout('components.layouts.app', ['title' => 'ساختار سازمانی'])]
class extends Component
{
    public $units = [];
    public $unitTypes = [];
    public $selectedUnit = null;
    public ?int $expandedUnitId = null;

    public function mount(): void
    {
        $this->unitTypes = \App\Models\UnitType::all();
        $this->loadTree();
    }

    public function loadTree(): void
    {
        $this->units = Unit::with(['childrenRecursive', 'unitType', 'assignedUsers', 'person'])
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedUnitId = $this->expandedUnitId === $id ? null : $id;
    }

    public function selectUnit(int $id): void
    {
        $this->selectedUnit = Unit::with(['parent', 'unitType', 'assignedUsers.person', 'person'])->find($id);
    }

    public function render()
    {
        return view('livewire.units.tree');
    }
};

    {{-- Unit Tree --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" dir="rtl">
        <div class="lg:col-span-2">
            <x-card shadow>
                <h2 class="font-bold text-lg mb-4 flex items-center gap-2">
                    <x-icon name="o-building-office" class="w-6 h-6 text-primary" />
                    ساختار سازمانی
                </h2>
                <div class="space-y-1">
                    @foreach($units as $unit)
                        @include('livewire.units._tree-node', ['unit' => $unit, 'level' => 0])
                    @endforeach
                </div>
            </x-card>
        </div>

        {{-- جزئیات واحد انتخاب شده --}}
        <div>
            @if($selectedUnit)
            <x-card shadow>
                <h3 class="font-bold mb-3">{{ $selectedUnit->name }}</h3>
                <div class="space-y-2 text-sm">
                    <div><span class="font-bold">نوع:</span> {{ $selectedUnit->unitType?->name ?? '---' }}</div>
                    <div><span class="font-bold">والد:</span> {{ $selectedUnit->parent?->name ?? '---' }}</div>
                    <div><span class="font-bold">کاربران:</span> {{ count($selectedUnit->assignedUsers) }}</div>
                </div>
                <div class="mt-4">
                    <h4 class="font-bold text-xs mb-2">کاربران این واحد:</h4>
                    @forelse($selectedUnit->assignedUsers as $u)
                    <div class="flex items-center gap-2 p-2 bg-base-200/50 rounded mb-1">
                        <x-icon name="o-user" class="w-4 h-4" />
                        <span class="text-xs">{{ $u->person?->f_name }} {{ $u->person?->l_name }}</span>
                    </div>
                    @empty
                    <p class="text-xs opacity-50">کاربری ندارد</p>
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
