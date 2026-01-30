<?php

use App\Models\Unit;

use Livewire\Volt\Component;

new class extends Component
{


    public $location = [];

    public function mount()
    {

$this->fetchlocation();
    }

    public function fetchlocation()
    {


        $query = Unit::where('lat','!=',NULL)->where('region_id','3');



        $this->location = $query->limit(2000)->select(['lat','lng'])->get()
            ->toArray();
        $this->js( json_encode($this->location));
    }
};
?>


<div>

    <x-header title=" لوکیشن " separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-card shadow class="p-0">
        <div class="container">
            <div wire:ignore>
                <livewire:maps.map/>
            </div>


        </div>
    </x-card>
</div>
@script
<script>
    let latlngs = [];
    let localog=@json($this->location);

    localog.forEach((latlng, index) => {
        L.marker(latlng).addTo(map);
    });
</script>
@endscript
