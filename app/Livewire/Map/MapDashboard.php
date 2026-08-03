<?php

namespace App\Livewire\Map;

use Livewire\Component;
use Livewire\Attributes\Url;

class MapDashboard extends Component
{
    #[Url(as: 'bbox', keepInSession: true)]
    public $bbox = null;
    
    #[Url(as: 'layer', keepInSession: true)]
    public $activeLayers = ['units'];
    
    #[Url(as: 'filter', keepInSession: true)]
    public $filters = [
        'hardware_type' => '',
        'ticket_priority' => '',
        'ticket_status' => '',
    ];

    public $mapCenter = [36.669343, 48.47163];
    public $mapZoom = 10;
    public $stats = [
        'units' => 0,
        'hardware' => 0,
        'open_tickets' => 0,
    ];
    
    public $mapToken = '';

    protected $listeners = [
        'mapMoved' => 'onMapMoved',
        'layerToggled' => 'onLayerToggled',
        'filterChanged' => 'onFilterChanged',
        'unitSelected' => 'onUnitSelected',
    ];

    public function mount()
    {
        $this->mapToken = auth()->user()->createToken('map-dashboard')->plainTextToken;
        $this->loadStats();
    }

    public function onMapMoved($data)
    {
        $this->mapCenter = $data['center'] ?? $this->mapCenter;
        $this->mapZoom = $data['zoom'] ?? $this->mapZoom;
        $this->bbox = $data['bbox'] ?? $this->bbox;
        $this->loadStats();
        $this->dispatch('mapViewportChanged', [
            'bbox' => $this->bbox,
            'zoom' => $this->mapZoom,
        ]);
    }

    public function onLayerToggled($layer)
    {
        if (in_array($layer, $this->activeLayers)) {
            $this->activeLayers = array_diff($this->activeLayers, [$layer]);
        } else {
            $this->activeLayers[] = $layer;
        }
    }

    public function onFilterChanged($filters)
    {
        $this->filters = array_merge($this->filters, $filters);
    }

    public function onUnitSelected($unitId)
    {
        $this->dispatch('showUnitDetails', ['unitId' => $unitId]);
    }

    public function loadUnitDetails($unitId)
    {
        $unit = \App\Models\Unit::with(['children', 'type'])->find($unitId);
        if (!$unit) {
            return ['error' => 'Unit not found'];
        }
        
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'type' => $unit->type?->name,
            'lat' => $unit->lat,
            'lng' => $unit->lng,
            'children_count' => $unit->children()->count(),
        ];
    }

    public function loadStats()
    {
        if (!$this->bbox) return;
        
        try {
            $response = \Illuminate\Support\Facades\Http::withToken($this->mapToken)
                ->get(route('api.gis.stats'), [
                    'bbox' => $this->bbox,
                ]);
            
            if ($response->successful()) {
                $this->stats = $response->json();
            }
        } catch (\Exception $e) {
            // Silently fail - stats are optional
        }
    }

    public function render()
    {
        return view('livewire.map.map-dashboard');
    }
}