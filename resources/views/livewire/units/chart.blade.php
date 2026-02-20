<?php

use App\Models\Unit;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;
    public string $search = '';
    // متغیری برای ذخیره وضعیت باز یا بسته بودن آیتم‌ها
    public array $expanded = [];
    // متد جستجو و باز کردن خودکار شاخه‌ها
    public function updatedSearch()
    {
        // پاکسازی لیست باز شده‌ها هنگام جستجوی جدید
        $this->expanded = [];

        if (strlen($this->search) > 2) {
            // پیدا کردن واحدهایی که نامشان مطابقت دارد
            $matchingUnits = Unit::where('name', 'LIKE', "%{$this->search}%")->get();

            foreach ($matchingUnits as $unit) {
                $this->expandParents($unit);
            }

            // حذف آی‌دی‌های تکراری
            $this->expanded = array_unique($this->expanded);
        }
    }

    // متد باز کردن والدین به صورت بازگشتی
    protected function expandParents($unit)
    {
        if ($unit->parent_id) {
            $this->expanded[] = (string)$unit->parent_id;
            $parent = Unit::find($unit->parent_id);
            if ($parent) {
                $this->expandParents($parent);
            }
        }
    }
    public function toggle($id)
    {
        if (in_array($id, $this->expanded)) {
            $this->expanded = array_diff($this->expanded, [$id]);
        } else {
            $this->expanded[] = $id;
        }
    }

    public function with(): array
    {
        $query = Unit::whereNull('parent_id')->with(['childrenRecursive', 'unitType']);

        return [
            'rootUnits' => $query->get()
        ];
    }
}; ?>

<div>
    <x-header title="ساختار درختی واحدها" separator progress-indicator>
        <x-slot:actions>
            <x-input
                placeholder="جستجو در واحدها..."
                wire:model.live.debounce.500ms="search"
                icon="o-magnifying-glass"
                class="input-sm"
                clearable />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        {{-- ظرف اصلی درخت با فونت یکپارچه --}}
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
        width: 2px; /* ضخامت خط عمودی */
        background-color: #040505; /* Gray-400 برای وضوح بیشتر */
    }
    .tree-line-leaf {
        position: absolute;
        right: -20px;
        top: 24px;
        width: 20px; /* طول خط افقی */
        height: 2px; /* ضخامت خط افقی */
        background-color: #040505;
    }
    /* استایل برای نقطه اتصال */
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