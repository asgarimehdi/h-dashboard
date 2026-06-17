<?php

use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mary\Traits\Toast;

return new class extends Component {
    use Toast;

    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function rules()
    {
        return [
            'currentPassword' => 'required',
            'newPassword' => 'required|min:8|different:currentPassword',
            'newPasswordConfirmation' => 'required|same:newPassword',
        ];
    }

    public function changePassword(): void
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
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="تغییر رمز ورود به سیستم" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <x-form wire:submit="changePassword" class="grid gap-4">
            <x-input 
                label="رمز فعلی" 
                type="password" 
                wire:model="currentPassword" 
                icon="o-key" 
                required
            />
            
            <x-input 
                label="رمز جدید" 
                type="password" 
                wire:model="newPassword" 
                icon="o-key" 
                required
            />
            
            <x-input 
                label="تأیید رمز جدید" 
                type="password" 
                wire:model="newPasswordConfirmation" 
                icon="o-key" 
                required
            />
            
            <x-errors title="خطا" description="لطفا موارد خطا را اصلاح نمائید" icon="o-face-frown" dir="rtl"/>
            
            <div class="flex gap-4">
                <x-button 
                    label="تغییر رمز" 
                    type="submit" 
                    icon="o-paper-airplane" 
                    class="btn-primary pl-6" 
                    spinner="changePassword" 
                />
                <x-button 
                    label="ریست" 
                    icon="o-x-mark" 
                    wire:click="$refresh" 
                    class="btn-default pl-6" 
                />
            </div>
        </x-form>
    </x-card>
</div>