<?php

use Livewire\Component;

return new class extends Component {
    // تعریف متغیر networkItems به عنوان پراپرتی کلاس
    public array $networkItems = [
        [
            'out-item-id' => '73638',
            'in-item-id'   => '73494',
            'title'   => 'فیبر اصلی',
            'initial-duration'     => '7200'
        ],
        [
            'out-item-id' => '77146',
            'in-item-id'   => '76954',
            'title'   => 'a سویچ',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '77154',
            'in-item-id'   => '76962',
            'title'   => 'b سویچ',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '77150',
            'in-item-id'   => '76958',
            'title'   => 'c سویچ',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '77178',
            'in-item-id'   => '76986',
            'title'   => 'e سویچ',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '73649',
            'in-item-id'   => '73505',
            'title'   => 'f سویچ',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74763',
            'in-item-id'   => '74610',
            'title'   => 'صائین هیدج عمید',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74794',
            'in-item-id'   => '74641',
            'title'   => 'هفده شهریور',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74798',
            'in-item-id'   => '74645',
            'title'   => 'مرکز 5',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74799',
            'in-item-id'   => '74646',
            'title'   => 'قروه شریف درسجین',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74771',
            'in-item-id'   => '74618',
            'title'   => 'شناط',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74797',
            'in-item-id'   => '74644',
            'title'   => 'بهورزی',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74802',
            'in-item-id'   => '74649',
            'title'   => 'دانشکده',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74767',
            'in-item-id'   => '74614',
            'title'   => 'خرم دره',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '74770',
            'in-item-id'   => '74617',
            'title'   => 'بیمارستان الغدیر',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '73647',
            'in-item-id'   => '73503',
            'title'   => 'بیمارستان امدادی',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '71639',
            'in-item-id'   => '71648',
            'title'   => 'وایرلس هیدج',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '71439',
            'in-item-id'   => '71448',
            'title'   => 'وایرلس صائین قلعه',
            'initial-duration'     => '7200'
        ],  
        [
            'out-item-id' => '77153',
            'in-item-id'   => '76961',
            'title'   => 'اشتراک',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '77186',
            'in-item-id'   => '76994',
            'title'   => 'آی تی یک',
            'initial-duration'     => '7200'
        ],     
        [
            'out-item-id' => '77171',
            'in-item-id'   => '76979',
            'title'   => 'آی تی سه',
            'initial-duration'     => '7200'
        ],    
        [
            'out-item-id' => '77169',
            'in-item-id'   => '76977',
            'title'   => 'هایپر یک',
            'initial-duration'     => '7200'
        ],     
        [
            'out-item-id' => '77184',
            'in-item-id'   => '76992',
            'title'   => 'هایپر دو',
            'initial-duration'     => '7200'
        ],     
        [
            'out-item-id' => '77164',
            'in-item-id'   => '76972',
            'title'   => 'هایپر سه',
            'initial-duration'     => '7200'
        ],     
        [
            'out-item-id' => '77155',
            'in-item-id'   => '76963',
            'title'   => 'پایش',
            'initial-duration'     => '7200'
        ],
    ];

    // متد اختیاری برای پردازش داده‌ها قبل از نمایش
    public function mount()
    {
        // می‌توانید داده‌ها را در اینجا پردازش کنید
        // یا از دیتابیس دریافت کنید
    }
};
?>

<div>
    <!-- HEADER -->
    <x-header title="داشبورد فناوری اطلاعات" separator progress-indicator>
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
            <h1 class="text-3xl font-bold mb-4">ترافیک شبکه</h1>
            @island(lazy:true)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($this->networkItems as $network)
                    <div class="flex-1">
                        <livewire:it.network-traffic-chart 
                            :out-item-id="$network['out-item-id']" 
                            :in-item-id="$network['in-item-id']"  
                            :title="$network['title']"  
                            :initial-duration="$network['initial-duration']"  
                        />
                    </div>
                @endforeach
            </div>
            @endisland
        </div>
    </x-card>
</div>