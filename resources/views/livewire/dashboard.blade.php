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

<livewire:network-traffic-chart 
    out-item-id="73638" 
    in-item-id="73494" 
    title="فیبر اصلی" 
    :initial-duration="7200" 
/>
<livewire:network-traffic-chart 
    out-item-id="74770" 
    in-item-id="74617" 
    title="alghadir" 
    :initial-duration="7200" 
/>
<livewire:network-traffic-chart 
    out-item-id="71639" 
    in-item-id="71648" 
    title="cp-hi" 
    :initial-duration="7200" 
/>

<livewire:network-traffic-chart 
    out-item-id="71439" 
    in-item-id="71448" 
    title="cp-sa" 
    :initial-duration="7200" 
/>
<livewire:network-traffic-chart 
    out-item-id="71610" 
    in-item-id="71619" 
    title="cp-amid" 
    :initial-duration="7200" 
/>
        </div>
    </x-card>
</div>
