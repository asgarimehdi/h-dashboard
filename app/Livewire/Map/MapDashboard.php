<?php

namespace App\Livewire\Map;

use Livewire\Component;
use Livewire\Attributes\Url;

class MapDashboard extends Component
{
    #[Url(as: 'bbox')]
    public $bbox = null;
    
    #[Url(as: 'layers')]
    public $layers = 'units';
    
    #[Url(as: 'filter_hardware')]
    public $filterHardware = '';
    
    #[Url(as: 'filter_priority')]
    public $filterPriority = '';
    
    #[Url(as: 'filter_status')]
    public $filterStatus = '';

    public $mapCenterLat = 36.669343;
    public $mapCenterLng = 48.47163;
    public $mapZoom = 10;
    public $statsUnits = 0;
    public $statsHardware = 0;
    public $statsOpenTickets = 0;
    
    public $mapToken = '';

    protected $listeners = [
        'mapMoved' => 'onMapMoved',
        'layerToggled' => 'onLayerToggled',
        'filterChanged' => 'onFilterChanged',
        'unitSelected' => 'onUnitSelected',
    ];

    public function mount()
    {
        $user = auth()->user();
        // Always create a new token — Sanctum stores only the hash,
        // so plainTextToken is null on tokens loaded from DB.
        // Delete old map-dashboard tokens first to avoid accumulation.
        $user->tokens()->where('name', 'map-dashboard')->delete();
        $this->mapToken = $user->createToken('map-dashboard')->plainTextToken;
        $this->loadStats();
    }

    public function onMapMoved($data)
    {
        $this->mapCenterLat = $data['center'][0] ?? $this->mapCenterLat;
        $this->mapCenterLng = $data['center'][1] ?? $this->mapCenterLng;
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
        $layers = explode(',', $this->layers);
        if (in_array($layer, $layers)) {
            $layers = array_diff($layers, [$layer]);
        } else {
            $layers[] = $layer;
        }
        $this->layers = implode(',', $layers);
    }

    public function onFilterChanged($filters)
    {
        foreach ($filters as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function onUnitSelected($unitId)
    {
        $this->dispatch('showUnitDetails', ['unitId' => $unitId]);
    }

    public function loadUnitDetails($unitId)
    {
        $accessibleIds = app(\App\Services\AccessService::class)->accessibleUnitIds();

        if (!in_array($unitId, $accessibleIds)) {
            return ['error' => 'Unit not found'];
        }

        $unit = \App\Models\Unit::with(['children', 'unitType'])->find($unitId);
        if (!$unit) {
            return ['error' => 'Unit not found'];
        }
        
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'type' => $unit->unitType?->name,
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
                $data = $response->json();
                $this->statsUnits = $data['units'] ?? 0;
                $this->statsHardware = $data['hardware'] ?? 0;
                $this->statsOpenTickets = $data['open_tickets'] ?? 0;
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