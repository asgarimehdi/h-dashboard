<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\{Auth, Hash};
use Illuminate\Validation\Rules\Password;

return new class extends Component
{
    public string $currentPassword = '';
    public string $password = '';
    public string $passwordConfirmation = '';

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = Auth::user();
        $user->password = Hash::make($this->password);
        $user->save();

        $this->reset(['currentPassword', 'password', 'passwordConfirmation']);

        $this->dispatch('swal', [
            'title' => 'رمز عبور با موفقیت تغییر کرد!',
            'icon' => 'success',
        ]);
    }

}; ?>
    {{-- Change Password Form --}}
    <div class="max-w-md mx-auto p-6" dir="rtl">
        <x-header title="تغییر رمز عبور" separator progress-indicator>
            <x-slot:actions>
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>

        <x-card shadow>
            <form wire:submit.prevent="changePassword" class="space-y-4">
                <div>
                    <x-input
                        label="رمز عبور فعلی"
                        type="password"
                        wire:model="currentPassword"
                        class="w-full" />
                    @error('currentPassword') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-input
                        label="رمز عبور جدید"
                        type="password"
                        wire:model="password"
                        class="w-full" />
                    @error('password') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <x-input
                        label="تکرار رمز عبور جدید"
                        type="password"
                        wire:model="passwordConfirmation"
                        class="w-full" />
                </div>

                <div class="pt-4">
                    <x-button label="تغییر رمز عبور" icon="o-lock-closed" class="btn-primary w-full" spinner />
                </div>
            </form>

            <div class="mt-6 p-4 bg-base-200/50 rounded-lg text-sm">
                <p class="font-bold mb-2">قوانین رمز عبور:</p>
                <ul class="list-disc list-inside space-y-1">
                    <li>حداقل ۸ کاراکتر</li>
                    <li>حداقل یک حرف بزرگ و یک حرف کوچک</li>
                    <li>حداقل یک عدد</li>
                    <li>حداقل یک نماد (!@#$%^&*)</li>
                </ul>
            </div>
        </x-card>
    </div>
