<?php

use Livewire\Volt\Component;

new class extends Component {
    public $selectedRole = null; // نقش انتخاب‌شده

    public function mount()
    {
        // گرفتن نقش‌های کاربر
        $roles = auth()->user()->roles;

        // اگر فقط یک نقش داره، مستقیم به داشبورد بره
        if ($roles->count() === 1) {
            session(['selected_role' => $roles->first()->id]);
            return redirect('/dashboard');
        }

        // اگر نقش انتخاب‌شده توی session باشه و کاربر برگرده به /، به داشبورد بره
        if (session()->has('selected_role')) {
            return redirect('/dashboard');
        }
    }

    public function selectRole()
    {
        // اعتبارسنجی که نقش انتخاب شده باشه
        if (!$this->selectedRole) {
            $this->addError('selectedRole', 'لطفاً یک نقش انتخاب کنید.');
            return;
        }

        // ذخیره نقش انتخاب‌شده توی session
        session(['selected_role' => $this->selectedRole]);
        return redirect('/dashboard');
    }

    public function getRolesProperty()
    {
        // گرفتن نقش‌های کاربر
        return auth()->user()->roles;
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
        <x-form wire:submit="selectRole">
            @foreach($this->roles as $role)
                <div class="mb-2 flex items-center">
                    <input
                        type="radio"
                        wire:model="selectedRole"
                        id="role-{{ $role->id }}"
                        value="{{ $role->id }}"
                        class="mr-2 h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                    />
                    <label for="role-{{ $role->id }}" class="text-gray-700">

                        <span class="text-sm text-gray-500"> - {{ $role->description ?? 'بدون توضیح' }}</span>
                    </label>
                </div>
            @endforeach
            <x-errors />
            <x-button
                type="submit"
                label="ورود به داشبورد"
                class="btn-primary mt-4"
                icon="o-arrow-right"
                spinner="selectRole"
            />
        </x-form>
    </x-card>

</div>
