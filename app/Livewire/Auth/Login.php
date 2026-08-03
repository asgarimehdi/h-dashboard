<?php

namespace App\Livewire\Auth;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Layout('components.layouts.auth')]
#[Title('Login')]
class Login extends Component
{
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

    public function rules(): array
    {
        return [
            'n_code' => 'required',
            'password' => 'required',
        ];
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

    public function render()
    {
        return view('livewire.auth.login');
    }
}