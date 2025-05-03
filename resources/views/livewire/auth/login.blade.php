<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

new
#[Layout('components.layouts.auth')]       // <-- Here is the `empty` layout
#[Title('Login')]
class extends Component {

    // #[Rule('required|email')]
    // public string $email = '';
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
};?>

<div>
    <x-nav sticky>
        <x-slot:brand>
            <x-app-brand/>
        </x-slot:brand>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-nav>
    <div class="flex items-center justify-center ">
        <x-card shadow class="m-5 p-5 w-full max-w-xl bg-base-300/60 shadow bg-linear-to-bl from-base-500 via-base-100 to-base-900" >

            <x-header  class="px-4 py-2" title="ورود" separator progress-indicator>
            </x-header>
            <x-form wire:submit="login">
                <x-input label="کد ملی" wire:model="n_code" icon="o-envelope"  />
                <x-input label="پسورد" wire:model="password" type="password" icon="o-key"  />
                <div class="password-options">
                    <x-checkbox wire:model="remember" label="مرا به خاطر بسپار" />
                </div>
                <x-errors title="خطا" description="لطفا موارد خطا را اصلاح نمائید" icon="o-face-frown" dir="rtl"/>
                <x-slot:actions>
                    <x-button label="ساخت حساب کاربری" class="btn-ghost" link="/register" />
                    <x-button label="ورود" type="submit" icon="o-paper-airplane" class="btn-primary" spinner="login" />
                </x-slot:actions>
            </x-form>
        </x-card>
    </div>
</div>
