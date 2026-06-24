<?php

use App\Models\Unit;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component {
    use Toast;
    
    public string $search = '';
    public array $expanded = [];
    public $rootUnits;

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

    <x-card shadow>
        <div class="tree-container text-right" dir="rtl">
            @forelse($rootUnits as $unit)
                @include('livewire.units.tree-item', ['unit' => $unit, 'level' => 0, 'isLast' => $loop->last])
            @empty
                <div class="text-center p-10 text-gray-400">موردی یافت نشد.</div>
            @endforelse
        </div>
    </x-card>

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