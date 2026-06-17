<?php

use Livewire\Component;

return new class extends Component {
    public $selectedRole = null;
    public $roleOptions = null;

    public function mount()
    {
        return redirect('/dashboard');
    }
};
?>

<div>
    <!-- HEADER -->
    <x-header title="انتخاب نقش" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- TABLE  -->
    <x-card shadow>
    </x-card>
</div>