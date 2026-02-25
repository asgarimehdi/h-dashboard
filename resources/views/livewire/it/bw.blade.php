<?php


use Livewire\Volt\Component;

new  class extends Component {

}; ?>



<div>
    <!-- HEADER -->
    <x-header title=" داشبورد فناوری اطلاعات" separator progress-indicator>
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
            <h1 class="text-3xl font-bold mb-4"> داشبورد فناوری اطلاعات</h1>

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

@php
$signalItems = [
    [
        'signalId' => '75297',
        'freqId'   => '71725',
        'respId'   => '70996',
        'name'     => 'سیگنال اعلایی'
    ],
    [
        'signalId' => '75231',
        'freqId'   => '71713',
        'respId'   => '71055',
        'name'     => 'سیگنال هفده شهریور'
    ],
    [
        'signalId' => '75312',
        'freqId'   => '71261',
        'respId'   => '70760',
        'name'     => 'سیگنال عمید آباد '
    ],
];
@endphp

{{-- بخش گیج‌های سیگنال --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
    @foreach($signalItems as $item)
        <livewire:signal-gauge 
            :signal-item-id="$item['signalId']"
            :frequency-item-id="$item['freqId']"
            :response-time-item-id="$item['respId']"
            :title="$item['name']"
            :min="-100"
            :max="-30"
            unit="dBm"
            frequency-unit="MHz"
            response-time-unit="ms"
        />
    @endforeach
</div>
        </div>
    </x-card>
</div>
