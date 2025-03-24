<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mary\Traits\Toast;
new class extends Component {
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';
    use Toast;
    public function rules()
    {
        return [
            'currentPassword' => 'required',
            'newPassword' => 'required|min:8|different:currentPassword',
            'newPasswordConfirmation' => 'required|same:newPassword',
        ];
    }

    public function changePassword()
    {
        $this->validate();

        $user = auth()->user();

        // چک کردن رمز فعلی
        if (!Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'رمز فعلی اشتباه است.',
            ]);
        }

        // تغییر رمز و ذخیره‌سازی
        $user->password = Hash::make($this->newPassword);
        $user->save();

        // ریست کردن فرم
        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $this->success("رمز با موفقیت تغییر یافت.", position: 'toast-bottom');

        // // نمایش پیام موفقیت
        // session()->flash('success', 'رمز با موفقیت تغییر یافت.');
    }
}; ?>

<div class="change-password-page">
    @if (session('success'))
    <div class="alert alert-success">
        {{ session('success') }}
    </div>
@endif
 <!-- HEADER -->
    <x-header title=" تغییر رمز ورود به سیستم" separator progress-indicator>
       
        <x-slot:actions>

            <x-theme-selector />
        </x-slot:actions>

    </x-header>

    

    <x-form wire:submit="changePassword">
        <x-input label="رمز فعلی" type="password" wire:model="currentPassword" icon="o-key" inline />
        <x-input label="رمز جدید" type="password" wire:model="newPassword" icon="o-key" inline />
        <x-input label="تأیید رمز جدید" type="password" wire:model="newPasswordConfirmation" icon="o-key" inline />
        <x-errors title="خطا" description="لطفا موارد خطا را اصلاح نمائید" icon="o-face-frown" dir="rtl" />
        <x-slot:actions>
            <x-button label="تغییر رمز" type="submit" icon="o-paper-airplane" class="btn-primary" spinner="changePassword" />
        </x-slot:actions>
    </x-form>
</div>