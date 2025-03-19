<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Hash;
use App\Models\Person;
new
#[Layout('components.layouts.auth')]       // <-- The same `empty` layout
#[Title('Login')]
class extends Component {

    // #[Rule('required|n_code|unique:persons')]
     public string $n_code = '';

    // #[Rule('required|confirmed')]
     public string $password = '';

    // #[Rule('required')]
     public string $password_confirmation = '';

    public function mount()
    {
        // It is logged in
        if (auth()->user()) {
            return redirect('/');
        }
    }

    public function register()
    {
         // اعتبارسنجی اولیه
    $this->validate([
        'n_code' => 'required|string|size:10',
        'password' => 'required|confirmed',
    ]);

    // بررسی اینکه کد ملی در `persons` وجود دارد یا نه
    $personExists = Person::where('n_code', $this->n_code)->exists();

    if (!$personExists) {
        $this->addError('n_code', 'کد ملی در سیستم ثبت نشده است.');
        return;
    }

    // بررسی اینکه کد ملی در `users` تکراری نباشد
    if (User::where('n_code', $this->n_code)->exists()) {
        $this->addError('n_code', 'این کد ملی قبلاً ثبت شده است.');
        return;
    }
    $user = User::create([
        'n_code' => $this->n_code,
        'password' => Hash::make($this->password),
    ]);

        auth()->login($user);

        request()->session()->regenerate();

        return redirect('/');
    }
}

; ?>

<div class="md:w-96 mx-auto mt-20">
    <div class="mb-10">Cool image here</div>

    <x-form wire:submit="register">
        <x-input label="n_coce" wire:model="n_code" icon="o-envelope" inline />
        <x-input label="Password" wire:model="password" type="password" icon="o-key" inline />
        <x-input label="Confirm Password" wire:model="password_confirmation" type="password" icon="o-key" inline />

        <x-slot:actions>
            <x-button label="Already registered?" class="btn-ghost" link="/login" />
            <x-button label="Register" type="submit" icon="o-paper-airplane" class="btn-primary" spinner="register" />
        </x-slot:actions>
    </x-form>
</div>
