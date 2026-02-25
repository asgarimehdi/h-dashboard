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
    title="بیمارستان الغدیر" 
    :initial-duration="7200" 
/>
<livewire:network-traffic-chart 
    out-item-id="71639" 
    in-item-id="71648" 
    title="وایرلس هیدج" 
    :initial-duration="7200" 
/>

<livewire:network-traffic-chart 
    out-item-id="71439" 
    in-item-id="71448" 
    title="وایرلس صائین قلعه" 
    :initial-duration="7200" 
/>

@php
$signalItems = [
    [
        'signalId' => '75297',
        'freqId'   => '71725',
        'respId'   => '70996',
        'name'     => 'اعلایی'
    ],
    [
        'signalId' => '75231',
        'freqId'   => '71713',
        'respId'   => '71055',
        'name'     => '17 شهریور'
    ],
      [
        'signalId' => '75470',
        'freqId'   => '69540',
        'respId'   => '69426',
        'name'     => 'شناط'
    ],
    [
        'signalId' => '75477',
        'freqId'   => '72019',
        'respId'   => '71831',
        'name'     => 'شریف آباد'
    ],
    [
        'signalId' => '75440',
        'freqId'   => '71719',
        'respId'   => '70937',
        'name'     => 'صائین قلعه'
    ],
    [
        'signalId' => '75418',
        'freqId'   => '71246',
        'respId'   => '70577',
        'name'     => 'مرکز5'
    ],
    [
        'signalId' => '75425',
        'freqId'   => '71266',
        'respId'   => '70701',
        'name'     => 'هیدج'
    ],
    [
        'signalId' => '75447',
        'freqId'   => '71707',
        'respId'   => '71173',
        'name'     => 'حسین آباد'
    ],
    [
        'signalId' => '75312',
        'freqId'   => '71261',
        'respId'   => '70760',
        'name'     => 'عمید آباد'
    ],
    [
        'signalId' => '75335',
        'freqId'   => '71735',
        'respId'   => '70819',
        'name'     => 'ارغوان'
    ],
    [
        'signalId' => '75342',
        'freqId'   => '71251',
        'respId'   => '70518',
        'name'     => 'بهورزی'
    ],
    [
        'signalId' => '75357',
        'freqId'   => '72009',
        'respId'   => '71949',
        'name'     => 'درسجین'
    ],
    [
        'signalId' => '75380',
        'freqId'   => '70165',
        'respId'   => '70098',
        'name'     => 'دکل قروه'
    ],
    [
        'signalId' => '75395',
        'freqId'   => '72014',
        'respId'   => '71890',
        'name'     => 'قروه'
    ],
  
];
@endphp

{{-- بخش گیج‌های سیگنال --}}
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mt-4">
    @foreach($signalItems as $item)
        <livewire:multi-gauge 
            :signal-item-id="$item['signalId']"
            :frequency-item-id="$item['freqId']"
            :response-time-item-id="$item['respId']"
            :title="$item['name']"
            :min="-85"
            :max="-45"
            unit="dBm"
            frequency-unit="MHz"
            response-time-unit="ms"
        />
    @endforeach
</div>
        </div>
    </x-card>
</div>
