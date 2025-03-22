<?php

use Livewire\Volt\Component;
use App\Models\Unit;
new class extends Component {
    public $units;         // لیست واحدهای سازمانی

          public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        // دریافت داده‌های اولیه جهت نمایش لیست و dropdown ها
        $this->units = Unit::with(['province', 'county', 'parent', 'unitType'])->get();
      
    }
 
}; ?>

<div>
     <x-header title="لیست واحد های سازمانی" separator progress-indicator>
       
        <x-slot:actions>
           
            <x-theme-toggle darkTheme="dark"  lightTheme="light"  />

        </x-slot:actions>
    </x-header>
  <!-- لیست واحدهای سازمانی -->
    <div class="mt-8">
        <h2 class="text-xl font-semibold mb-2">Units List</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full border border-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-gray-600">Name</th>
                        <th class="px-4 py-2 text-left text-gray-600">Type</th>
                        <th class="px-4 py-2 text-left text-gray-600">Province</th>
                        <th class="px-4 py-2 text-left text-gray-600">County</th>
                        <th class="px-4 py-2 text-left text-gray-600">Parent Unit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($units as $unit)
                        <tr>
                            <td class="px-4 py-2">{{ $unit->name }}</td>
                            <td class="px-4 py-2">
                                {{ $unit->unitType ? $unit->unitType->name : '-' }}
                            </td>
                            <td class="px-4 py-2">
                                {{ $unit->province ? $unit->province->name : '-' }}
                            </td>
                            <td class="px-4 py-2">
                                {{ $unit->county ? $unit->county->name : '-' }}
                            </td>
                            <td class="px-4 py-2">
                                {{ $unit->parent ? $unit->parent->name . ' (' . ($unit->parent->unitType ? $unit->parent->unitType->name : '-') . ')' : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>
    
</div>
