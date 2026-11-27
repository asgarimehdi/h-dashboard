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

    public bool $showHelpModal = false;

    public function rules()
    {
        return [
            'currentPassword' => 'required|max:255',
            'newPassword' => 'required|max:255|min:8|different:currentPassword',
            'newPasswordConfirmation' => 'required|max:255|same:newPassword',
        ];
    }

    public function changePassword(): void
    {
        try {
            $this->validate();

            $user = auth()->user();

            // #1719: قفل per-account پیش از هر وریفای رمز فعلی.
            $this->ensureIsNotRateLimited();

            // چک کردن رمز فعلی
            if (!Hash::check($this->currentPassword, $user->password)) {
                // #1719: فقط حدسِ ناموفقِ «رمز فعلی» بودجه را می‌سوزاند.
                \Illuminate\Support\Facades\RateLimiter::hit($this->throttleKey(), self::THROTTLE_DECAY);

                throw ValidationException::withMessages([
                    'currentPassword' => 'رمز فعلی اشتباه است.',
                ]);
            }

            // #1004: بستن نشست های وبِ دیگر روی دستگاه ها، پیش از تغییر رمز. این متد
            // رمز فعلی را وریفای می کند، پس باید پیش از ذخیرهٔ رمز جدید اجرا شود.
            // با FQCN صدا زده می شود تا افزودنِ یک import، شمارهٔ خطِ رکوردهای
            // phpstan-baseline این فایل را جابجا نکند.
            \Illuminate\Support\Facades\Auth::logoutOtherDevices($this->currentPassword);

            // تغییر رمز و ذخیره‌سازی
            $user->password = Hash::make($this->newPassword);
            $user->save();

            // باطل کردن تمام توکن‌ها پس از تغییر رمز
            $user->tokens()->delete();

            // #1719: تغییر موفق، بودجهٔ همین اکشن را آزاد می‌کند.
            \Illuminate\Support\Facades\RateLimiter::clear($this->throttleKey());

            $this->success("رمز با موفقیت تغییر یافت.", position: 'toast-bottom');
        } finally {
            // #1912: هیچ رمز plaintextی نباید در snapshot برگردد. این ریست عمداً
            // در همهٔ شاخه‌ها (از جمله خطای اعتبارسنجی) اجرا می‌شود.
            $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        }
    }

    // #1719: ۵ حدس ناموفق رمز فعلی در هر ۱۵ دقیقه، به‌ازای هر حساب.
    protected const THROTTLE_MAX_ATTEMPTS = 5;
    protected const THROTTLE_DECAY = 900;

    protected function ensureIsNotRateLimited(): void
    {
        if (! \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($this->throttleKey(), self::THROTTLE_MAX_ATTEMPTS)) {
            return;
        }

        $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'currentPassword' => __('auth.change_password_throttle', [
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]),
        ]);
    }

    /**
     * #1719: بودجهٔ مستقل از IP و از بودجهٔ لاگین (`login-account:`)، از شناسهٔ
     * پایدار کاربر احرازشده — نه ورودی فرم — و هش‌شده، چون RateLimiter::hit()
     * خودش کلید را هش نمی‌کند.
     */
    protected function throttleKey(): string
    {
        return 'change-password-account:'.hash('sha256', 'user:'.(string) auth()->id());
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="تغییر رمز ورود به سیستم" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="change-password" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" section="change-password" />

    <div class="flex justify-center">
        <x-card shadow class="w-full max-w-xl">
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

                {{-- #957: role="alert" تا خطای رمز واقعاً اعلام شود، نه فقط دیده. --}}
                <x-errors role="alert" title="خطا" description="لطفا موارد خطا را اصلاح نمائید" icon="o-face-frown" dir="rtl"/>

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
</div>