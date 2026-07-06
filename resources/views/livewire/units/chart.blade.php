<?php

use App\Models\Unit;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component {
    use Toast;
    
    public string $search = '';
    public array $expanded = [];
    public $rootUnits;
    public $selectedUnit = null;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->rootUnits = Unit::whereNull('parent_id')
            ->with(['childrenRecursive', 'unitType'])
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->expanded = [];

        if (strlen($this->search) > 2) {
            $matchingUnits = Unit::where('name', 'LIKE', "%{$this->search}%")->get();

            foreach ($matchingUnits as $unit) {
                $this->expandParents($unit);
            }

            $this->expanded = array_unique($this->expanded);
        }
    }

    protected function expandParents($unit): void
    {
        if ($unit->parent_id) {
            $this->expanded[] = (string)$unit->parent_id;
            $parent = Unit::find($unit->parent_id);
            if ($parent) {
                $this->expandParents($parent);
            }
        }
    }
    
    public function toggle($id): void
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_diff($this->expanded, [$id]);
        } else {
            $this->expanded[] = $id;
        }
    }

    public function selectUnit(int $id): void
    {
        $this->selectedUnit = Unit::with(['parent', 'unitType', 'assignedUsers.person', 'person'])->find($id);
    }
    
}; ?>

<div>
    <x-header title="ساختار درختی واحدها" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
            <x-input
                placeholder="جستجو در واحدها..."
                wire:model.live.debounce.500ms="search"
                icon="o-magnifying-glass"
                class="input-sm"
                clearable />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6" dir="rtl">
        <div class="lg:col-span-3">
            <x-card shadow>
                <div class="tree-container text-right" dir="rtl">
                    @forelse($rootUnits as $unit)
                        @include('livewire.units.tree-item', ['unit' => $unit, 'level' => 0, 'isLast' => $loop->last])
                    @empty
                        <div class="text-center p-10 text-gray-400">موردی یافت نشد.</div>
                    @endforelse
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