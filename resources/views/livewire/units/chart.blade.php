<?php

 use Livewire\Volt\Component;
 use App\Models\Unit;

 new class extends Component {
      public $treeData;
      public $units;         // لیست واحدهای سازمانی

       public function mount()
     {
         $this->loadData();
     }
      public function loadData()
     {
         $this->units = Unit::with(['province', 'county', 'parent', 'unitType'])->get();

         $this->treeData = $this->units->map(function ($unit) {
              return [
                   'id'    => (string) $unit->id,
                   'parent' => $unit->parent_id ? (string) $unit->parent_id : '',
                   'name'  => $unit->name,
                   ];
                 })->toArray();



     }
 }; ?>
<div>
    <x-header title="نمودار چارت سازمانی" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>
    <div id="containerChart" class="rounded-box shadow-neutral h-200"></div>
</div>



 <script>
     (function() {
     const chartData = @json($treeData);

     Highcharts.chart('containerChart', {
         chart: {
             inverted: true,
             marginBottom: 10
         },
         title: {
             text: 'نمودار چارت سازمانی',
             align: 'center'
         },
         series: [{
             type: 'treegraph',
             data: chartData,
             tooltip: {
                 pointFormat: '{point.name}'
             },
             dataLabels: {
                 pointFormat: '{point.name}',
                 style: {
                     whiteSpace: 'nowrap',
                     color: '#200000',
                     textOutline: '3px contrast'
                 },
                 crop: false
             },
             marker: {
                 radius: 6
             },
             levels: [
                 {
                     level: 1,
                     dataLabels: {
                         align: 'center',
                         x: 20
                     }
                 },
                 {
                     level: 2,
                     colorByPoint: true,
                     dataLabels: {
                         verticalAlign: 'bottom',
                         y: -20
                     }
                 },
                 {
                    level: 3,
                     colorByPoint: true,
                     dataLabels: {
                         verticalAlign: 'bottom',
                         y: -20
                     }
                 },
                  {
                      level: 4,
                     colorByPoint: true,
                     dataLabels: {
                         verticalAlign: 'bottom',
                         y: -20
                     }
                 },
                 {
                     level: 5,
                     colorByPoint: true,
                     dataLabels: {
                         verticalAlign: 'bottom',
                         y: -20
                     }
                 },
                 {
                      level: 6,
                     colorByPoint: true,
                     dataLabels: {
                         verticalAlign: 'bottom',
                         y: -20,

                     }
                 },
                 {
                     level: 7,
                     colorVariation: {
                         key: 'brightness',
                         to: -0.5
                     },
                     dataLabels: {
                         verticalAlign: 'top',
                         rotation: 90,
                         y: 20
                     }
                 }

             ]
         }]
     });
     })();
 </script>
