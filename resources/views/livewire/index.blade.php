<?php

use Livewire\Volt\Component;

new class extends Component {
    public $selectedRole = null; // نقش انتخاب‌شده
    public $roleOptions = null; // نقش انتخاب‌شده

    public function mount()
    {

            return redirect('/dashboard');

    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="انتخاب نقش " separator progress-indicator>
        <x-slot:middle class="!justify-end">
            {{-- Search moved below --}}
        </x-slot:middle>
        <x-slot:actions>
            {{-- Create button moved below --}}
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- TABLE  -->
    <x-card shadow>


    </x-card>

</div>
