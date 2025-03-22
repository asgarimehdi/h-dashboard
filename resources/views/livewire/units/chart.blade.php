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
         <div id="container"></div>
 
 </div>
 <script>
     (function() {
     const chartData = @json($treeData);
 
     Highcharts.chart('container', {
         chart: {
             inverted: true,
             marginBottom: 170
         },
         title: {
             text: 'نمودار چارت سازمانی',
             align: 'left'
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
                     color: '#000000',
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