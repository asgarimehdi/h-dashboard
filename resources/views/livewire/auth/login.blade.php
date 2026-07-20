<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Component; // <--- ✅ تغییر اصلی: حذف Volt
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

new
#[Layout('components.layouts.auth')]
#[Title('Login')]
class extends Component {

    #[Rule('required')]
    public string $n_code = '';

    #[Rule('required')]
    public string $password = '';

    public bool $remember = false;

    public function mount()
    {
        // It is logged in
        if (auth()->user()) {
            return redirect('/');
        }
    }

    public function login()
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['n_code' => $this->n_code, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'n_code' => __('نام کاربری یا رمز عبور اشتباه است'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        // اطمینان از ذخیره remember me
        Auth::login(auth()->user(), $this->remember);

        // ثبت فعالیت ورود
        \App\Services\ActivityLogService::login('ورود موفق به سیستم با کد ملی: ' . $this->n_code);

        return redirect()->intended('/');
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'n_code' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->n_code).'|'.request()->ip());
    }
};

?>

{{--  ponytail: no external libs, pure CSS animations, glassmorphism card  --}}
<div class="min-h-screen flex auth-layout">
    {{-- Background --}}
    <div class="fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-primary/20 via-base-100 to-secondary/20 dark:from-primary/10 dark:via-base-300 dark:to-secondary/10"></div>
        <div class="absolute top-[-20%] -right-[10%] w-[70%] h-[70%] rounded-full bg-primary/10 blur-3xl animate-pulse" style="animation-duration:8s"></div>
        <div class="absolute bottom-[-15%] -left-[10%] w-[60%] h-[60%] rounded-full bg-secondary/10 blur-3xl animate-pulse" style="animation-duration:12s;animation-delay:-4s"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[80%] h-[80%] rounded-full bg-accent/5 blur-3xl animate-pulse" style="animation-duration:15s;animation-delay:-8s"></div>
    </div>

    {{-- Brand side (hidden on mobile) --}}
    <div class="hidden lg:flex w-1/2 flex-col justify-between p-12 bg-gradient-to-br from-primary to-secondary text-primary-content relative overflow-hidden">
        {{-- Decorative circles --}}
        <div class="absolute -top-20 -right-20 w-72 h-72 rounded-full bg-white/10"></div>
        <div class="absolute -bottom-10 -left-10 w-48 h-48 rounded-full bg-white/5"></div>

        <div class="relative z-10">
            <x-app-brand class="text-3xl font-bold" />
        </div>

        <div class="relative z-10 space-y-6">
            <h1 class="text-4xl font-bold leading-tight">داشبورد مدیریت سلامت</h1>
            <p class="text-primary-content/80 text-lg leading-relaxed">سیستم یکپارچه مدیریت منابع انسانی، نقشه‌های GIS، پایش IT و مدیریت درخواست‌ها</p>
            <div class="flex gap-6 pt-4 text-sm text-primary-content/70">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-primary-content" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    امنیت بالا
                </span>
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-primary-content" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    رابط کاربری مدرن
                </span>
            </div>
        </div>
    </div>

    {{-- Login form side --}}
    <div class="flex-1 flex items-center justify-center p-6 sm:p-12">
        <div class="w-full max-w-md">
            {{-- Mobile brand (only on small screens) --}}
            <div class="lg:hidden text-center mb-8">
                <x-app-brand class="text-2xl font-bold mx-auto mb-2" />
                <p class="text-base-content/60 text-sm">داشبورد مدیریت سلامت</p>
            </div>

            {{-- Glass card --}}
            <div class="backdrop-blur-xl bg-base-100/80 border border-base-content/10 rounded-3xl shadow-2xl p-8 sm:p-10 space-y-8">

                {{-- Header --}}
                <div class="text-center space-y-2">
                    <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-primary to-secondary flex items-center justify-center mb-4 shadow-lg shadow-primary/30">
                        <svg class="w-8 h-8 text-primary-content" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold">ورود به حساب کاربری</h2>
                    <p class="text-base-content/50 text-sm">اطلاعات حساب خود را وارد کنید</p>
                </div>

                {{-- Errors --}}
                <x-errors title="خطا" description="لطفا موارد خطا را اصلاح نمائید" icon="o-face-frown" class="border-error/30 bg-error/5" />

                {{-- Form --}}
                <form wire:submit="login" class="space-y-5">
                    {{-- Username field --}}
                    <div class="relative group">
                        <div class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-base-content/40 group-focus-within:text-primary transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <input
                            wire:model="n_code"
                            type="text"
                            id="n_code"
                            required
                            autocomplete="username"
                            placeholder=" "
                            class="peer input input-lg w-full pr-12 bg-base-200/50 border-base-content/10 focus:border-primary focus:bg-base-200 rounded-xl transition-all duration-300 hover:bg-base-200/70"
                        />
                        <label for="n_code" class="absolute right-12 top-1/2 -translate-y-1/2 text-base-content/40 pointer-events-none transition-all duration-300
                            peer-focus:-top-2.5 peer-focus:text-xs peer-focus:text-primary peer-focus:px-1 peer-focus:bg-base-100 peer-focus:right-10
                            peer-[:not(:placeholder-shown)]:-top-2.5 peer-[:not(:placeholder-shown)]:text-xs peer-[:not(:placeholder-shown)]:px-1 peer-[:not(:placeholder-shown)]:bg-base-100 peer-[:not(:placeholder-shown)]:right-10
                           ">
                            کد ملی
                        </label>
                    </div>

                    {{-- Password field --}}
                    <div class="relative group" x-data="{ show: false }">
                        <div class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-base-content/40 group-focus-within:text-primary transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                            </svg>
                        </div>
                        <input
                            wire:model="password"
                            :type="show ? 'text' : 'password'"
                            id="password"
                            required
                            autocomplete="current-password"
                            placeholder=" "
                            class="peer input input-lg w-full pr-12 bg-base-200/50 border-base-content/10 focus:border-primary focus:bg-base-200 rounded-xl transition-all duration-300 hover:bg-base-200/70"
                        />
                        <label for="password" class="absolute right-12 top-1/2 -translate-y-1/2 text-base-content/40 pointer-events-none transition-all duration-300
                            peer-focus:-top-2.5 peer-focus:text-xs peer-focus:text-primary peer-focus:px-1 peer-focus:bg-base-100 peer-focus:right-10
                            peer-[:not(:placeholder-shown)]:-top-2.5 peer-[:not(:placeholder-shown)]:text-xs peer-[:not(:placeholder-shown)]:px-1 peer-[:not(:placeholder-shown)]:bg-base-100 peer-[:not(:placeholder-shown)]:right-10
                           ">
                            رمز عبور
                        </label>
                        {{-- Toggle password visibility --}}
                        <button type="button" @click="show = !show" class="absolute inset-y-0 left-0 flex items-center pl-4 text-base-content/40 hover:text-primary transition-colors">
                            <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Remember & forgot --}}
                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input wire:model="remember" type="checkbox" class="checkbox checkbox-primary checkbox-sm" />
                            <span class="text-base-content/60">مرا به خاطر بسپار</span>
                        </label>
                        <a href="#" class="text-primary hover:underline">فراموشی رمز عبور؟</a>
                    </div>

                    {{-- Submit --}}
                    <button type="submit" wire:loading.attr="disabled" class="btn btn-primary btn-lg w-full shadow-lg shadow-primary/30 hover:shadow-xl hover:shadow-primary/40 transition-all duration-300 group">
                        <span wire:loading.remove>ورود به سیستم</span>
                        <span wire:loading>
                            <span class="loading loading-spinner loading-sm"></span>
                            در حال ورود...
                        </span>
                    </button>
                </form>

                {{-- Footer --}}
                <p class="text-center text-sm text-base-content/50">
                    حساب کاربری ندارید؟
                    <a href="/register" class="text-primary font-medium hover:underline">ثبت‌نام کنید</a>
                </p>
            </div>

            {{-- Theme toggle for desktop --}}
            <div class="hidden sm:flex justify-center mt-6">
                <x-theme-selector />
            </div>
        </div>
    </div>
</div>