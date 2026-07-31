<?php

namespace App\Livewire\Maps;

use Livewire\Component;
use App\Models\Zone;
use App\Models\Unit;
use App\Models\Region;
use App\Services\AccessService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Cache;

#[Layout('components.layouts.app')]
#[Title('نقشه زون‌ها')]
class ZoneMap extends Component
{
    use Toast;

    public bool $showHelpModal = false;
    public array $selectedZoneIds = [];
    public array $availableZones = [];
    public array $selectedRegions = [];
    public array $availableRegions = [];
    public string $search = '';
    public bool $showUnits = true;

    public function mount(): void
    {
        $this->loadAvailableZones();
        $this->loadAvailableRegions();
    }

    public function loadAvailableZones(): void
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
        
        $this->availableZones = Zone::where('is_active', true)
            ->whereHas('units', function ($query) use ($accessibleUnitIds) {
                $query->whereIn('units.id', $accessibleUnitIds);
            })
            ->select('id', 'name', 'color', 'description', 'slug')
            ->withCount(['units' => function ($query) use ($accessibleUnitIds) {
                $query->whereIn('units.id', $accessibleUnitIds);
            }])
            ->orderBy('name')
            ->get()
            ->map(fn($z) => [
                'id' => $z->id,
                'name' => $z->name,
                'color' => $z->color,
                'description' => $z->description,
                'slug' => $z->slug,
                'units_count' => $z->units_count,
            ])
            ->toArray();
    }

    public function loadAvailableRegions(): void
    {
        $this->availableRegions = Region::where('type', 'county')
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function updatedSelectedZoneIds(): void
    {
        $this->loadZoneUnits();
    }

    public function updatedSelectedRegions(): void
    {
        $this->loadCountyBoundaries();
    }

    public function loadZoneUnits(): void
    {
        if (empty($this->selectedZoneIds)) {
            $this->dispatch('zone-units-loaded', units: []);
            $this->dispatch('zone-boundaries-loaded', boundaries: []);
            return;
        }

        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
        
        // Load zones with their boundaries and units
        $zones = Zone::whereIn('id', $this->selectedZoneIds)
            ->with([
                'units' => function ($query) use ($accessibleUnitIds) {
                    $query->whereIn('units.id', $accessibleUnitIds)
                          ->whereNotNull('boundary_id')
                          ->with('boundary:id,unit_id,geojson');
                }
            ])
            ->get();

        $units = [];
        $boundaries = [];

        foreach ($zones as $zone) {
            // Add zone boundary if it has one
            if ($zone->boundary_id) {
                $zoneBoundary = $zone->boundary;
                if ($zoneBoundary && $zoneBoundary->geojson) {
                    $boundaries[] = [
                        'id' => 'zone-' . $zone->id,
                        'name' => $zone->name,
                        'color' => $zone->color,
                        'geojson' => $zoneBoundary->geojson,
                        'is_zone_boundary' => true,
                    ];
                }
            }

            // Add unit boundaries
            foreach ($zone->units as $unit) {
                if ($unit->boundary && $unit->boundary->geojson) {
                    $units[] = [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'zone_id' => $zone->id,
                        'zone_name' => $zone->name,
                        'zone_color' => $zone->color,
                        'geojson' => $unit->boundary->geojson,
                    ];
                }
            }
        }

        $this->dispatch('zone-units-loaded', units: $units);
        $this->dispatch('zone-boundaries-loaded', boundaries: $boundaries);
    }

    public function loadCountyBoundaries(): void
    {
        if (empty($this->selectedRegions)) {
            $this->dispatch('county-boundaries-loaded', counties: []);
            return;
        }

        $cacheKey = 'county_boundaries_' . md5(implode(',', $this->selectedRegions));

        $counties = Cache::remember($cacheKey, 300, function () {
            return Region::whereIn('id', $this->selectedRegions)
                ->whereNotNull('boundary_id')
                ->with('boundary')
                ->get()
                ->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'geojson' => $r->boundary?->geojson,
                ])
                ->filter(fn($r) => $r['geojson'])
                ->values()
                ->toArray();
        });

        $this->dispatch('county-boundaries-loaded', counties: $counties);
    }

    public function render()
    {
        return view('livewire.maps.zone-map');
    }
}