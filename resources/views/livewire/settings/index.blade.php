<?php

use App\Support\ThemeFamilies;
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

    /** #1224 — light pole; one of ThemeFamilies::LIGHT. */
    public string $lightTheme = ThemeFamilies::DEFAULT_LIGHT;

    /** #1224 — dark pole; one of ThemeFamilies::DARK. */
    public string $darkTheme = ThemeFamilies::DEFAULT_DARK;

    public function mount(): void
    {
        $settings = auth()->user()->settings ?? [];
        $this->browserNotifications = $settings['browser_notifications'] ?? false;
        $this->dashboardRefresh = $settings['dashboard_refresh'] ?? 0;
        $this->compactMode = $settings['compact_mode'] ?? false;

        // Stale or hand-edited values must never reach the toggle's Alpine
        // strings (see ThemeFamilies), so validate and fall back here too.
        $this->lightTheme = ThemeFamilies::poleOrDefault($settings['light_theme'] ?? null, 'light');
        $this->darkTheme = ThemeFamilies::poleOrDefault($settings['dark_theme'] ?? null, 'dark');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function lightOptions(): array
    {
        return ThemeFamilies::lightOptions();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function darkOptions(): array
    {
        return ThemeFamilies::darkOptions();
    }

    public function save(): void
    {
        // Whitelist BEFORE touching the database. These values are interpolated
        // into single-quoted Alpine expressions on every page, so an unknown
        // theme would silently fall back to the :root theme with no warning.
        if (! ThemeFamilies::isValid($this->lightTheme, 'light')) {
            $this->error('تم روشن انتخاب‌شده معتبر نیست.', position: 'toast-bottom');

            return;
        }

        if (! ThemeFamilies::isValid($this->darkTheme, 'dark')) {
            $this->error('تم تیره انتخاب‌شده معتبر نیست.', position: 'toast-bottom');

            return;
        }

        $user = auth()->user();
        // Rewrite the whole array: `settings` is a single JSON column, so a
        // partial array here would delete every key this save does not name.
        $user->settings = [
            'browser_notifications' => $this->browserNotifications,
            'dashboard_refresh' => $this->dashboardRefresh,
            'compact_mode' => $this->compactMode,
            'light_theme' => $this->lightTheme,
            'dark_theme' => $this->darkTheme,
        ];
        $user->save();

        // Scenario 1 (issue comment 6364662792): the applied theme may not be
        // one of the two new poles. The page's Alpine handler migrates it to
        // the same-family pole and re-broadcasts `theme-changed`.
        $this->dispatch('theme-poles-saved', lightTheme: $this->lightTheme, darkTheme: $this->darkTheme);

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

        <x-card shadow class="mt-4">
            <h2 class="font-bold mb-4">ظاهر (تم)</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="font-bold text-sm">تم روشن</label>
                    <x-select name="lightTheme" wire:model.live="lightTheme" :options="$this->lightOptions()"
                              option-value="value" option-label="label" class="mt-1" />
                </div>
                <div>
                    <label class="font-bold text-sm">تم تیره</label>
                    <x-select name="darkTheme" wire:model.live="darkTheme" :options="$this->darkOptions()"
                              option-value="value" option-label="label" class="mt-1" />
                </div>
            </div>
            <p class="mt-3 text-xs opacity-60">
                دکمهٔ تم در نوار بالا بین همین دو تم جابه‌جا می‌شود.
            </p>
        </x-card>

        <div class="mt-6 flex justify-end gap-2">
            <x-button label="تست اعلان" icon="o-bell" wire:click="sendTestNotification" class="btn-outline btn-sm" spinner />
            <x-button label="تست بروزرسانی" icon="o-arrow-path" wire:click="testDashboardRefresh" class="btn-outline btn-sm" spinner />
            <x-button label="تست نما" icon="o-eye" wire:click="testCompactMode" class="btn-outline btn-sm" spinner />
            <x-button label="ذخیره تنظیمات" icon="o-check" wire:click="save" class="btn-primary" spinner />
        </div>
    </div>

@script
<script>
    // #1224 scenario 1: after saving new poles, an applied theme that is not
    // one of them would make the toggle's icon and first click undefined
    // (MaryUI only knows the two poles). Migrate it to the same-family pole.
    $wire.on('theme-poles-saved', ({ lightTheme, darkTheme }) => {
        const root = document.documentElement;
        const current = root.getAttribute('data-theme')
            || (localStorage.getItem('mary-theme') || '').replaceAll('"', '');

        const lightThemes = @js(\App\Support\ThemeFamilies::LIGHT);
        const darkThemes = @js(\App\Support\ThemeFamilies::DARK);
        const poles = [lightTheme, darkTheme];

        if (!current || poles.includes(current)) {
            return;
        }

        const target = lightThemes.includes(current)
            ? lightTheme
            : (darkThemes.includes(current) ? darkTheme : lightTheme);

        root.setAttribute('data-theme', target);
        // MaryUI's $persist stores JSON, including the quotes — the head script
        // and the storage listener both strip them with replaceAll('"').
        localStorage.setItem('mary-theme', JSON.stringify(target));

        // Keep the layout blobs and the HR charts in sync without a reload.
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: target, bubbles: true }));
    });
</script>
@endscript
