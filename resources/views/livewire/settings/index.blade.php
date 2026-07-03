<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

return new class extends Component
{
    public bool $emailNotifications = true;
    public bool $browserNotifications = false;
    public int $dashboardRefresh = 0; // 0 = off
    public bool $compactMode = false;

    public function mount(): void
    {
        $settings = auth()->user()->settings ?? [];
        $this->emailNotifications = $settings['email_notifications'] ?? true;
        $this->browserNotifications = $settings['browser_notifications'] ?? false;
        $this->dashboardRefresh = $settings['dashboard_refresh'] ?? 0;
        $this->compactMode = $settings['compact_mode'] ?? false;
    }

    public function save(): void
    {
        $user = auth()->user();
        $user->settings = [
            'email_notifications' => $this->emailNotifications,
            'browser_notifications' => $this->browserNotifications,
            'dashboard_refresh' => $this->dashboardRefresh,
            'compact_mode' => $this->compactMode,
        ];
        $user->save();

        $this->success('تنظیمات ذخیره شد!', position: 'toast-bottom');
    }

}; ?>

    {{-- Settings HTML --}}
    <div class="max-w-2xl mx-auto p-6" dir="rtl">
        <x-header title="تنظیمات" separator progress-indicator>
            <x-slot:actions>
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>

        <x-card shadow>
            <h2 class="font-bold mb-4">اعلان‌ها</h2>
            <div class="space-y-4">
                <label class="flex items-center justify-between cursor-pointer">
                    <span>اعلان ایمیلی</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model="emailNotifications" />
                </label>
                <label class="flex items-center justify-between cursor-pointer">
                    <span>اعلان مرورگر</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model="browserNotifications" />
                </label>
            </div>
        </x-card>

        <x-card shadow class="mt-4">
            <h2 class="font-bold mb-4">نمای داشبورد</h2>
            <div class="space-y-4">
                <div>
                    <label class="font-bold text-sm">بروزرسانی خودکار</label>
                    <select class="select select-bordered w-full" wire:model="dashboardRefresh">
                        <option value="0">غیرفعال</option>
                        <option value="15">هر ۱۵ ثانیه</option>
                        <option value="30">هر ۳۰ ثانیه</option>
                        <option value="60">هر ۱ دقیقه</option>
                    </select>
                </div>
                <label class="flex items-center justify-between cursor-pointer">
                    <span>حالت فشرده</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model="compactMode" />
                </label>
            </div>
        </x-card>

        <div class="mt-6 flex justify-end">
            <x-button label="ذخیره تنظیمات" icon="o-check" wire:click="save" class="btn-primary" spinner />
        </div>
    </div>
