<?php

use Livewire\Volt\Component;

new class extends Component {
    public $selectedRole = null; // نقش انتخاب‌شده
    public $roleOptions = null; // نقش انتخاب‌شده

    public function mount()
    {
        // گرفتن نقش‌های کاربر
        $roles = auth()->user()->roles;
        $this->roleOptions = $this->roles->pluck('description', 'id')->all();
        $this->roleOptions = $this->roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->description, // description رو به name مپ می‌کنیم
            ];
        })->all();

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
    <x-header title="انتخاب نقش " separator progress-indicator>
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

        <x-form wire:submit="selectRole">
            <x-group
                label="انتخاب نقش"
                wire:model="selectedRole"
                :options="$this->roleOptions"
            />

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
