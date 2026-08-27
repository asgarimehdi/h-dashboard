<?php
use Livewire\Component;
use Livewire\Attributes\Url;
return new class extends Component
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

        $unit = \App\Models\Unit::with(['children', 'unitType'])->withCount('children')->find($unitId);
        if (!$unit) {
            return ['error' => 'Unit not found'];
        }
        
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'type' => $unit->unitType?->name,
            'lat' => $unit->lat,
            'lng' => $unit->lng,
            'children_count' => $unit->children_count,
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
};
?>

<div
    wire:ignore
    id="map-container"
    class="w-full h-[calc(100vh-4rem)] relative"
    x-data="mapDashboard()"
    x-init="initMap()"
>
    <!-- Map Toolbar -->
    <div class="absolute top-4 left-4 right-4 z-10 flex flex-wrap gap-2 justify-between p-2 bg-base-100/90 backdrop-blur rounded-box shadow-lg">
        <!-- Layer Controls -->
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-base-content/70">لایه‌ها:</span>
            <button type="button"
                   class="btn btn-xs btn-ghost gap-1"
                   :class="{ 'btn-primary': activeLayers.includes('units') }"
                   @click="toggleLayer('units')">
                <i class="fa fa-map-marker-alt"></i> واحدها
            </button>
            <button type="button"
                   class="btn btn-xs btn-ghost gap-1"
                   :class="{ 'btn-primary': activeLayers.includes('hardware') }"
                   @click="toggleLayer('hardware')">
                <i class="fa fa-desktop"></i> سخت‌افزار
            </button>
            <button type="button"
                   class="btn btn-xs btn-ghost gap-1"
                   :class="{ 'btn-primary': activeLayers.includes('tickets') }"
                   @click="toggleLayer('tickets')">
                <i class="fa fa-ticket"></i> تیکت‌ها
            </button>
        </div>

        <!-- Filters -->
        <div class="flex items-center gap-2">
            <select x-model="filters.hardware_type" @change="setFilter('hardware_type', $event.target.value)"
                    class="select select-sm w-32" x-show="activeLayers.includes('hardware')">
                <option value="">همه انواع</option>
                <option value="laptop">لپ‌تاپ</option>
                <option value="pc">دسکتاپ</option>
                <option value="server">سرور</option>
                <option value="printer">پرینتر</option>
            </select>

            <select x-model="filters.ticket_priority" @change="setFilter('ticket_priority', $event.target.value)"
                    class="select select-sm w-28" x-show="activeLayers.includes('tickets')">
                <option value="">همه اولویت‌ها</option>
                <option value="urgent">فوری</option>
                <option value="high">بالا</option>
                <option value="normal">عادی</option>
                <option value="medium">متوسط</option>
                <option value="low">پایین</option>
            </select>

            <select x-model="filters.ticket_status" @change="setFilter('ticket_status', $event.target.value)"
                    class="select select-sm w-28" x-show="activeLayers.includes('tickets')">
                <option value="">همه وضعیت‌ها</option>
                <option value="created">ایجاد شده</option>
                <option value="forwarded">ارجاع شده</option>
                <option value="accepted">پذیرفته شده</option>
                <option value="completed">تکمیل شده</option>
            </select>
        </div>

        <!-- Stats Panel -->
        <div class="flex items-center gap-4 text-sm">
            <div class="badge badge-primary gap-1">
                <i class="fa fa-map-marker-alt"></i>
                <span>واحدها: <span x-text="stats.units"></span></span>
            </div>
            <div class="badge badge-secondary gap-1">
                <i class="fa fa-desktop"></i>
                <span>سخت‌افزار: <span x-text="stats.hardware"></span></span>
            </div>
            <div class="badge badge-warning gap-1">
                <i class="fa fa-ticket"></i>
                <span>تیکت باز: <span x-text="stats.open_tickets"></span></span>
            </div>
        </div>
    </div>

    <!-- Map Element -->
    <div id="map" class="w-full h-full"></div>

    <!-- Unit Details Modal -->
    <div x-data="{ unitId: null, unitDetails: null, loading: false }"
         @showUnitDetails.window="unitId = $event.detail.unitId; loading = true; unitDetails = null; $nextTick(() => $refs.modal.showModal()); loadUnitDetails()">
        <dialog x-ref="modal" class="modal modal-bottom sm:modal-middle">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4">جزئیات واحد</h3>
                <div x-show="loading" class="loading loading-spinner loading-lg"></div>
                <div x-show="!loading && unitDetails" class="space-y-2">
                    <div><strong>نام:</strong> <span x-text="unitDetails?.name"></span></div>
                    <div><strong>نوع:</strong> <span x-text="unitDetails?.type"></span></div>
                    <div><strong>موقعیت:</strong> <span x-text="(unitDetails?.lat ?? '') + ', ' + (unitDetails?.lng ?? '')"></span></div>
                    <div><strong>زیرمجموعه‌ها:</strong> <span x-text="unitDetails?.children_count"></span></div>
                </div>
                <div x-show="!loading && unitDetails && unitDetails.error" class="text-error" x-text="unitDetails?.error"></div>
                <div class="modal-action mt-4">
                    <form method="dialog"><button class="btn">بستن</button></form>
                </div>
            </div>
        </dialog>
    </div>

    <script>
        function loadUnitDetails() {
            if (!this.unitId) return;
            @this('loadUnitDetails', this.unitId).then(r => {
                this.loading = false;
                this.unitDetails = r;
            });
        }
    </script>
</div>

<script>
    function mapDashboard() {
        return {
            map: null,
            markers: {},
            layers: {},
            activeLayers: ['units'],
            filters: {
                hardware_type: '',
                ticket_priority: '',
                ticket_status: '',
            },
            stats: { units: 0, hardware: 0, open_tickets: 0 },
            currentBbox: null,
            apiToken: '{{ $mapToken }}',
            apiBase: '{{ url('/api/gis') }}',
            center: [{{ $mapCenterLat }}, {{ $mapCenterLng }}],
            zoom: {{ $mapZoom }},

            initMap() {
                if (!window.L) return;

                this.map = L.map('map', {
                    center: this.center,
                    zoom: this.zoom,
                    zoomControl: true,
                    attributionControl: true,
                });

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap contributors'
                }).addTo(this.map);

                this.layers = {
                    units: L.layerGroup().addTo(this.map),
                    hardware: L.layerGroup(),
                    tickets: L.layerGroup(),
                };

                this.map.on('moveend', () => this.onMapMove());

                this.onMapMove();
            },

            onMapMove() {
                if (!this.map) return;

                clearTimeout(this._moveTimer);

                this._moveTimer = setTimeout(() => {
                    const bounds = this.map.getBounds();
                    this.currentBbox = [
                        bounds.getWest(),
                        bounds.getSouth(),
                        bounds.getEast(),
                        bounds.getNorth()
                    ].join(',');

                    this.loadLayers();
                }, 300);
            },

            async loadLayers() {
                if (!this.currentBbox) return;

                const bbox = this.currentBbox;
                const headers = {
                    'Authorization': 'Bearer ' + this.apiToken,
                    'Accept': 'application/json',
                };

                if (this.activeLayers.includes('units')) {
                    this.fetchAndRender(`${this.apiBase}/units?bbox=${bbox}`, this.layers.units, 'unit', headers);
                }
                if (this.activeLayers.includes('hardware')) {
                    let url = `${this.apiBase}/hardware?bbox=${bbox}`;
                    if (this.filters.hardware_type) url += `&type=${this.filters.hardware_type}`;
                    this.fetchAndRender(url, this.layers.hardware, 'hardware', headers);
                }
                if (this.activeLayers.includes('tickets')) {
                    let url = `${this.apiBase}/tickets?bbox=${bbox}`;
                    if (this.filters.ticket_priority) url += `&priority=${this.filters.ticket_priority}`;
                    if (this.filters.ticket_status) url += `&status=${this.filters.ticket_status}`;
                    this.fetchAndRender(url, this.layers.tickets, 'ticket', headers);
                }

                // Load stats
                this.fetchStats(bbox, headers);
            },

            async fetchAndRender(url, layerGroup, type, headers) {
                try {
                    const response = await fetch(url, { headers });
                    const data = await response.json();
                    this.renderGeoJSON(data, layerGroup, type);
                } catch (e) {
                    console.error(`Failed to load ${type}:`, e);
                }
            },

            async fetchStats(bbox, headers) {
                try {
                    const response = await fetch(`${this.apiBase}/stats?bbox=${bbox}`, { headers });
                    const data = await response.json();
                    this.stats = data;
                } catch (e) {
                    console.error('Failed to load stats:', e);
                }
            },

            renderGeoJSON(geojson, layerGroup, type) {
                if (!layerGroup) return;
                layerGroup.clearLayers();

                if (!geojson.features || geojson.features.length === 0) return;

                geojson.features.forEach(feature => {
                    if (!feature.geometry) return;

                    const props = feature.properties;
                    const coords = feature.geometry.coordinates;
                    const lat = coords[1];
                    const lng = coords[0];

                    let marker;
                    const iconColor = this.getIconColor(type, props);

                    if (type === 'unit') {
                        marker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'unit-marker',
                                html: `<div style="width:18px;height:18px;border-radius:50%;background:${iconColor};border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>`,
                                iconSize: [18, 18],
                            })
                        });
                    } else if (type === 'hardware') {
                        marker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'hardware-marker',
                                html: `<div style="width:14px;height:14px;border-radius:50%;background:${iconColor};border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>`,
                                iconSize: [14, 14],
                            })
                        });
                    } else if (type === 'ticket') {
                        marker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'ticket-marker',
                                html: `<div style="width:12px;height:12px;transform:rotate(45deg);background:${iconColor};border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>`,
                                iconSize: [12, 12],
                            })
                        });
                    }

                    if (marker) {
                        marker.bindPopup(this.createPopup(type, props));
                        marker.on('click', () => this.onFeatureClick(type, props));
                        marker.addTo(layerGroup);
                    }
                });
            },

            getIconColor(type, props) {
                if (type === 'unit') {
                    return '#3b82f6';
                } else if (type === 'hardware') {
                    return props.shutdown ? '#ef4444' : '#22c55e';
                } else if (type === 'ticket') {
                    const colors = {
                        'urgent': '#ef4444',
                        'high': '#f97316',
                        'normal': '#eab308',
                        'medium': '#3b82f6',
                        'low': '#22c55e',
                    };
                    return colors[props.priority] || '#6b7280';
                }
                return '#6b7280';
            },

            createPopup(type, props) {
                const escapeHtml = (str) => {
                    const div = document.createElement('div');
                    div.textContent = str ?? '';
                    return div.innerHTML;
                };

                if (type === 'unit') {
                    return `
                        <div dir="rtl" style="min-width:200px;font-family:inherit;">
                            <strong>${escapeHtml(props.name)}</strong><br>
                            نوع: ${escapeHtml(this.getUnitTypeLabel(props.unit_type_id))}<br>
                            موقعیت: ${props.lat?.toFixed(6)}, ${props.lng?.toFixed(6)}
                        </div>`;
                } else if (type === 'hardware') {
                    return `
                        <div dir="rtl" style="min-width:200px;font-family:inherit;">
                            <strong>${escapeHtml(props.pc_name)}</strong><br>
                            نوع: ${escapeHtml(props.type || '—')}<br>
                            CPU: ${escapeHtml(props.cpu || '—')}<br>
                            RAM: ${escapeHtml(props.ram || '—')}<br>
                            وضعیت: ${props.shutdown ? 'شات‌داون' : 'فعال'}<br>
                            واحد: ${escapeHtml(props.unit?.name || '—')}<br>
                            شخص: ${escapeHtml(props.person?.name || '—')}
                        </div>`;
                } else if (type === 'ticket') {
                    return `
                        <div dir="rtl" style="min-width:200px;font-family:inherit;">
                            <strong>${escapeHtml(props.title)}</strong><br>
                            کد: ${escapeHtml(props.ticket_code)}<br>
                            اولویت: ${escapeHtml(this.getPriorityLabel(props.priority))}<br>
                            وضعیت: ${escapeHtml(props.status)}<br>
                            واحد: ${escapeHtml(props.unit?.name || '—')}
                        </div>`;
                }
                return '';
            },

            getUnitTypeLabel(typeId) {
                const types = {
                    1: 'وزارت بهداشت', 2: 'دانشگاه علوم پزشکی', 3: 'معاونت بهداشت',
                    4: 'شبکه بهداشت', 5: 'مرکز خدمات جامع سلامت شهری',
                    6: 'مرکز خدمات جامع سلامت شهری روستایی', 7: 'مرکز خدمات جامع سلامت روستایی',
                    9: 'خانه بهداشت', 13: 'مرکز هاری', 18: 'خانه بهداشت کارگری', 20: 'خانه های کارگری',
                };
                return types[typeId] || 'نامشخص';
            },

            getPriorityLabel(priority) {
                const labels = {
                    'urgent': 'فوری', 'high': 'بالا', 'normal': 'عادی',
                    'medium': 'متوسط', 'low': 'پایین',
                };
                return labels[priority] || priority;
            },

            getPriorityBadge(priority) {
                const badges = {
                    'urgent': 'badge-error', 'high': 'badge-warning', 'normal': 'badge-info',
                    'medium': 'badge-primary', 'low': 'badge-success',
                };
                return badges[priority] || 'badge-ghost';
            },

            onFeatureClick(type, props) {
                if (type === 'unit') {
                    @this('unitSelected', props.id);
                }
            },

            toggleLayer(layer) {
                if (this.activeLayers.includes(layer)) {
                    this.activeLayers = this.activeLayers.filter(l => l !== layer);
                    this.layers[layer]?.clearLayers();
                    this.map.removeLayer(this.layers[layer]);
                } else {
                    this.activeLayers.push(layer);
                    if (this.layers[layer] && !this.map.hasLayer(this.layers[layer])) {
                        this.layers[layer].addTo(this.map);
                    }
                    this.loadLayers();
                }
                @this('layerToggled', layer);
            },

            setFilter(key, value) {
                this.filters[key] = value;
                @this('filterChanged', this.filters);
                this.loadLayers();
            },
        };
    }
</script>

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
@endpush
