<?php

use Livewire\Component;

return new class extends Component {
    // تعریف متغیر signalItems به عنوان پراپرتی کلاس
    public array $signalItems = [
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

    // متد mount برای پردازش اولیه
    public function mount()
    {
        // می‌توانید داده‌ها را از دیتابیس دریافت کنید
        // یا پردازش‌های اولیه را انجام دهید
    }
};
?>

<div>
    <!-- HEADER -->
    <x-header title="دستگاه های بی سیم" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- TABLE  -->
    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <div class="flex-1">
            </div>
        </div>
        
        <div class="p-6">
            {{-- بخش گیج‌های سیگنال با Lazy Loading --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 mt-4">
                @foreach($this->signalItems as $item)
                    @php
                        // ایجاد key یکتا برای هر کامپوننت
                        $key = 'gauge-' . $item['signalId'] . '-' . $item['freqId'] . '-' . $item['respId'];
                    @endphp
                    
                    {{-- استفاده از lazy با کلید یکتا --}}
                    <livewire:it.multi-gauge 
                        :signal-item-id="$item['signalId']"
                        :frequency-item-id="$item['freqId']"
                        :response-time-item-id="$item['respId']"
                        :title="$item['name']"
                        :min="-85"
                        :max="-45"
                        unit="dBm"
                        frequency-unit="MHz"
                        response-time-unit="ms"
                        :key="$key"
                        lazy
                    />
                @endforeach
            </div>
        </div>
    </x-card>
</div>