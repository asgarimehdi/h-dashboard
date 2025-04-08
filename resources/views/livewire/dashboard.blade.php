<?php


use Livewire\Volt\Component;

new  class extends Component {
    public function mount()
    {
        // اگر نقش انتخاب‌شده توی session نباشه، برگرده به صفحه انتخاب نقش
        if (!session()->has('selected_role')) {
            return redirect('/');
        }
    }

    public function getSelectedRoleNameProperty()
    {
        // گرفتن نام نقش انتخاب‌شده
        return auth()->user()->roles->find(session('selected_role'))->description ?? 'نقش نامشخص';
    }

    public function changeRole()
    {
        // پاک کردن نقش انتخاب‌شده از session
        session()->forget('selected_role');
        // ریدایرکت به صفحه انتخاب نقش
        return redirect('/');
    }
}; ?>



<div>
    <!-- HEADER -->
    <x-header title="مدیریت وضعیت های ردیف سازمانی" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            {{-- Search moved below --}}
        </x-slot:middle>
        <x-slot:actions>
            {{-- Create button moved below --}}
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- TABLE  -->
    <x-card shadow>
        {{-- Search and Create Button Area --}}
        <div class="flex gap-2 items-center mb-4"> {{-- Added margin-bottom --}}

            <div class="flex-1">

            </div>
        </div>
        <div class="p-6">
            <h1 class="text-3xl font-bold mb-4">خوش آمدید به داشبورد</h1>
            <p class="text-lg mb-4">نقش انتخاب‌شده: {{ $this->selectedRoleName }}</p>
            <x-button
                wire:click="changeRole"
                label="تغییر نقش"
                class="btn-secondary"
                icon="o-arrow-path"
                spinner="changeRole"
            />
        </div>
    </x-card>
</div>
