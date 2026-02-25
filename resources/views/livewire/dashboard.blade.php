<?php


use Livewire\Volt\Component;

new  class extends Component {

}; ?>



<div>
    <!-- HEADER -->
    <x-header title=" داشبورد مدیریت اطلاعات سلامت" separator progress-indicator>
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
        {{-- Search and Create Button Area --}}
        <div class="flex gap-2 items-center mb-4"> {{-- Added margin-bottom --}}

            <div class="flex-1">

            </div>
        </div>
        <div class="p-6">
            <h1 class="text-3xl font-bold mb-4">خوش آمدید به داشبورد</h1>

        </div>
    </x-card>
</div>
