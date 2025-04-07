<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
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
}; ?>

<div class="p-6">
    <h1 class="text-3xl font-bold mb-4">  به داشبورد مدیریت اطلاعات بهداشت خوش آمدید </h1>
    <p class="text-lg">نقش انتخاب‌شده: {{ $this->selectedRoleName }}</p>
</div>