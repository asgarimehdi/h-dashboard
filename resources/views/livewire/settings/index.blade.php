<?php

use Livewire\Attributes\Layout;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;

    public bool $browserNotifications = false;

    public int $dashboardRefresh = 0; // 0 = off

    public bool $compactMode = false;

    public bool $showHelpModal = false;

    public function mount(): void
    {
        $settings = auth()->user()->settings ?? [];
        $this->browserNotifications = $settings['browser_notifications'] ?? false;
        $this->dashboardRefresh = $settings['dashboard_refresh'] ?? 0;
        $this->compactMode = $settings['compact_mode'] ?? false;
    }

    public function save(): void
    {
        $user = auth()->user();
        $user->settings = [
            'browser_notifications' => $this->browserNotifications,
            'dashboard_refresh' => $this->dashboardRefresh,
            'compact_mode' => $this->compactMode,
        ];
        $user->save();

        $this->success('تنظیمات ذخیره شد!', position: 'toast-bottom');
    }

    public function notifyPermissionDenied(): void
    {
        $this->error('دسترسی اعلان مرورگر رد شد. لطفاً از تنظیمات مرورگر اعلان را فعال کنید.', position: 'toast-bottom');
    }

    public function notifyUnsupported(): void
    {
        $this->error('مرورگر شما از اعلان‌ها پشتیبانی نمی‌کند.', position: 'toast-bottom');
    }

    public function sendTestNotification(): void
    {
        if (! $this->browserNotifications) {
            $this->error('اعلان مرورگر غیرفعال است', position: 'toast-bottom');

            return;
        }

        $this->dispatch('browser-notification', [
            'title' => 'تست اعلان',
            'body' => 'این یک اعلان تستی از داشبورد سلامت است.',
            'url' => '/dashboard',
        ]);

        $this->success('اعلان ارسال شد!', position: 'toast-bottom');
    }

    public function testDashboardRefresh(): void
    {
        if ($this->dashboardRefresh === 0) {
            $this->error('بروزرسانی خودکار غیرفعال است', position: 'toast-bottom');

            return;
        }

        $this->success("داشبورد هر {$this->dashboardRefresh} ثانیه بروزرسانی می‌شود", position: 'toast-bottom');
    }

    public function testCompactMode(): void
    {
        if ($this->compactMode) {
            $this->success('حالت فشرده فعال شد', position: 'toast-bottom');
        } else {
            $this->success('حالت عادی فعال شد', position: 'toast-bottom');
        }
    }
}; ?>

    <div class="max-w-2xl mx-auto p-6" dir="rtl">
        <x-header title="تنظیمات" separator progress-indicator>
            <x-slot:actions>
                <x-help:button section="settings" wireModel="showHelpModal" />
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>

        <x-help:modal wireModel="showHelpModal" />

        <x-card shadow>
            <h2 class="font-bold mb-4">اعلان‌ها</h2>
            <div class="space-y-4">
                <label class="flex items-center justify-between cursor-pointer">
                    <span>اعلان مرورگر</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model.live="browserNotifications"
                           x-on:change="if($event.target.checked && 'Notification' in window) { Notification.requestPermission().then(p => { if(p !== 'granted') { $wire.set('browserNotifications', false); $wire.notifyPermissionDenied(); } }) } else if($event.target.checked && !('Notification' in window)) { $wire.set('browserNotifications', false); $wire.notifyUnsupported(); }" />
                </label>
            </div>
        </x-card>

        <x-card shadow class="mt-4">
            <h2 class="font-bold mb-4">نمای داشبورد</h2>
            <div class="space-y-4">
                <div>
                    <label class="font-bold text-sm">بروزرسانی خودکار</label>
                    <select class="select select-bordered w-full" wire:model.live="dashboardRefresh">
                        <option value="0">غیرفعال</option>
                        <option value="15">هر ۱۵ ثانیه</option>
                        <option value="30">هر ۳۰ ثانیه</option>
                        <option value="60">هر ۱ دقیقه</option>
                    </select>
                </div>
                <label class="flex items-center justify-between cursor-pointer">
                    <span>حالت فشرده</span>
                    <input type="checkbox" class="toggle toggle-primary" wire:model.live="compactMode" />
                </label>
            </div>
        </x-card>

        <div class="mt-6 flex justify-end gap-2">
            <x-button label="تست اعلان" icon="o-bell" wire:click="sendTestNotification" class="btn-outline btn-sm" spinner />
            <x-button label="تست بروزرسانی" icon="o-arrow-path" wire:click="testDashboardRefresh" class="btn-outline btn-sm" spinner />
            <x-button label="تست نما" icon="o-eye" wire:click="testCompactMode" class="btn-outline btn-sm" spinner />
            <x-button label="ذخیره تنظیمات" icon="o-check" wire:click="save" class="btn-primary" spinner />
        </div>
    </div>
