<div 
    wire:ignore 
    id="map-container" 
    class="w-full h-[calc(100vh-4rem)] relative"
    x-data="{
        map: null,
        markers: {},
        layers: {},
        currentBbox: null,
        zoom: @entangle($mapZoom).defer,
        center: @entangle($mapCenter).defer,
        activeLayers: @entangle($activeLayers).defer,
        filters: @entangle($filters).defer,
        stats: @entangle($stats).defer,
        
        initMap() {
            // Initialize Leaflet map
            this.map = L.map('map', {
                center: this.center,
                zoom: this.zoom,
                zoomControl: true,
                attributionControl: true,
            });
            
            // Add OpenStreetMap tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap contributors'
            }).addTo(this.map);
            
            // Initialize layer groups
            this.layers = {
                units: L.layerGroup().addTo(this.map),
                hardware: L.layerGroup(),
                tickets: L.layerGroup(),
                clusters: L.layerGroup(),
            };
            
            // Watch for map movements
            this.map.on('moveend', () => this.onMapMove());
            this.map.on('zoomend', () => this.onMapMove());
            
            // Initial load
            this.loadLayers();
        },
        
        onMapMove() {
            if (!this.map) return;
            
            const bounds = this.map.getBounds();
            const bbox = [
                bounds.getWest(),
                bounds.getSouth(),
                bounds.getEast(),
                bounds.getNorth()
            ].join(',');
            
            this.currentBbox = bbox;
            this.center = [this.map.getCenter().lat, this.map.getCenter().lng];
            this.zoom = this.map.getZoom();
            
            // Notify Livewire
            @this('mapMoved', { 
                center: this.center, 
                zoom: this.zoom, 
                bbox: this.currentBbox 
            });
            
            // Reload layers with new bounds
            this.loadLayers();
        },
        
        async loadLayers() {
            if (!this.currentBbox) return;
            
            const bbox = this.currentBbox;
            
            // Load units layer
            if (this.activeLayers.includes('units')) {
                await this.loadUnits(bbox);
            } else {
                this.layers.units.clearLayers();
            }
            
            // Load hardware layer
            if (this.activeLayers.includes('hardware')) {
                await this.loadHardware(bbox);
            } else {
                this.layers.hardware.clearLayers();
            }
            
            // Load tickets layer
            if (this.activeLayers.includes('tickets')) {
                await this.loadTickets(bbox);
            } else {
                this.layers.tickets.clearLayers();
            }
        },
        
        async loadUnits(bbox) {
            try {
                const response = await fetch(`{{ route('api.gis.units') }}?bbox=${bbox}`, {
                    headers: {
                        'Authorization': `Bearer {{ auth()->user()->createToken('map')->plainTextToken }}`,
                        'Accept': 'application/json',
                    }
                });
                const data = await response.json();
                this.renderGeoJSON(data, this.layers.units, 'unit');
            } catch (e) {
                console.error('Failed to load units:', e);
            }
        },
        
        async loadHardware(bbox) {
            try {
                const type = this.filters.hardware_type;
                const url = `{{ route('api.gis.hardware') }}?bbox=${bbox}` + (type ? `&type=${type}` : '');
                const response = await fetch(url, {
                    headers: {
                        'Authorization': `Bearer {{ auth()->user()->createToken('map')->plainTextToken }}`,
                        'Accept': 'application/json',
                    }
                });
                const data = await response.json();
                this.renderGeoJSON(data, this.layers.hardware, 'hardware');
            } catch (e) {
                console.error('Failed to load hardware:', e);
            }
        },
        
        async loadTickets(bbox) {
            try {
                const priority = this.filters.ticket_priority;
                const status = this.filters.ticket_status;
                let url = `{{ route('api.gis.tickets') }}?bbox=${bbox}`;
                if (priority) url += `&priority=${priority}`;
                if (status) url += `&status=${status}`;
                
                const response = await fetch(url, {
                    headers: {
                        'Authorization': `Bearer {{ auth()->user()->createToken('map')->plainTextToken }}`,
                        'Accept': 'application/json',
                    }
                });
                const data = await response.json();
                this.renderGeoJSON(data, this.layers.tickets, 'ticket');
            } catch (e) {
                console.error('Failed to load tickets:', e);
            }
        },
        
        renderGeoJSON(geojson, layerGroup, type) {
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
                    marker = L.circleMarker([lat, lng], {
                        radius: 8,
                        fillColor: iconColor,
                        color: '#fff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.8,
                    });
                } else if (type === 'hardware') {
                    marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'hardware-marker',
                            html: `<i class=\"fa fa-desktop\" style=\"color: ${iconColor}; font-size: 20px;\"></i>`,
                            iconSize: [20, 20],
                        })
                    });
                } else if (type === 'ticket') {
                    marker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'ticket-marker',
                            html: `<i class=\"fa fa-ticket\" style=\"color: ${iconColor}; font-size: 20px;\"></i>`,
                            iconSize: [20, 20],
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
                return '#3b82f6'; // blue
            } else if (type === 'hardware') {
                return props.shutdown ? '#ef4444' : '#22c55e'; // red if shutdown, green if active
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
            if (type === 'unit') {
                return `
                    <div class=\"p-2 min-w-[200px]\">
                        <h4 class=\"font-bold text-base-content mb-2\">${props.name}</h4>
                        <div class=\"text-xs text-base-content/70 space-y-1\">
                            <div>نوع: ${this.getUnitTypeLabel(props.unit_type_id)}</div>
                            <div>موقعیت: ${props.lat?.toFixed(6)}, ${props.lng?.toFixed(6)}</div>
                        </div>
                        <div class=\"mt-2 flex gap-2\">
                            <button onclick=\"livewire.emit('showUnitDetails', {unitId: ${props.id}})\" 
                                    class=\"btn btn-sm btn-primary flex-1\">جزئیات واحد</button>
                        </div>
                    </div>
                `;
            } else if (type === 'hardware') {
                return `
                    <div class=\"p-2 min-w-[200px]\">
                        <h4 class=\"font-bold text-base-content mb-2\">${props.pc_name}</h4>
                        <div class=\"text-xs text-base-content/70 space-y-1\">
                            <div>نوع: ${props.type || '—'}</div>
                            <div>CPU: ${props.cpu || '—'}</div>
                            <div>RAM: ${props.ram || '—'}</div>
                            <div>وضعیت: <span class=\"${props.shutdown ? 'text-error' : 'text-success'}\">${props.shutdown ? 'شات‌داون' : 'فعال'}</span></div>
                            <div>واحد: ${props.unit?.name || '—'}</div>
                            <div>شخص: ${props.person?.name || '—'}</div>
                        </div>
                    </div>
                `;
            } else if (type === 'ticket') {
                return `
                    <div class=\"p-2 min-w-[200px]\">
                        <h4 class=\"font-bold text-base-content mb-2\">${props.title}</h4>
                        <div class=\"text-xs text-base-content/70 space-y-1\">
                            <div>کد: ${props.ticket_code}</div>
                            <div>اولویت: <span class=\"badge ${this.getPriorityBadge(props.priority)}\">${this.getPriorityLabel(props.priority)}</span></div>
                            <div>وضعیت: <span class=\"badge badge-info\">${props.status}</span></div>
                            <div>واحد: ${props.unit?.name || '—'}</div>
                        </div>
                    </div>
                `;
            }
            return '';
        },
        
        getUnitTypeLabel(typeId) {
            const types = {
                1: 'وزارت بهداشت',
                2: 'دانشگاه علوم پزشکی',
                3: 'معاونت بهداشت',
                4: 'شبکه بهداشت',
                5: 'مرکز خدمات جامع سلامت شهری',
                6: 'مرکز خدمات جامع سلامت شهری روستایی',
                7: 'مرکز خدمات جامع سلامت روستایی',
                9: 'خانه بهداشت',
                13: 'مرکز هاری',
                18: 'خانه بهداشت کارگری',
                20: 'خانه های کارگری',
            };
            return types[typeId] || 'نامشخص';
        },
        
        getPriorityLabel(priority) {
            const labels = {
                'urgent': 'فوری',
                'high': 'بالا',
                'normal': 'عادی',
                'medium': 'متوسط',
                'low': 'پایین',
            };
            return labels[priority] || priority;
        },
        
        getPriorityBadge(priority) {
            const badges = {
                'urgent': 'badge-error',
                'high': 'badge-warning',
                'normal': 'badge-info',
                'medium': 'badge-primary',
                'low': 'badge-success',
            };
            return badges[priority] || 'badge-ghost';
        },
        
        onFeatureClick(type, props) {
            if (type === 'unit') {
                @this('unitSelected', props.id);
            }
        },
        
        // Layer toggle handlers
        toggleLayer(layer) {
            if (this.activeLayers.includes(layer)) {
                this.activeLayers = this.activeLayers.filter(l => l !== layer);
                this.layers[layer]?.clearLayers();
            } else {
                this.activeLayers.push(layer);
                this.loadLayers();
            }
            @this('layerToggled', layer);
        },
        
        // Filter handlers
        setFilter(key, value) {
            this.filters[key] = value;
            @this('filterChanged', this.filters);
            this.loadLayers();
        },
    }"
    x-init="initMap()"
>
    <!-- Map Toolbar -->
    <div class="absolute top-4 left-4 right-4 z-10 flex flex-wrap gap-2 justify-between p-2 bg-base-100/90 backdrop-blur rounded-box shadow-lg">
        <!-- Layer Controls -->
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-base-content/70">لایه‌ها:</span>
            <label class="btn btn-xs btn-ghost gap-1" 
                   :class="{ 'btn-primary': activeLayers.includes('units') }"
                   @click="toggleLayer('units')">
                <input type="checkbox" :checked="activeLayers.includes('units')" @change="toggleLayer('units')" class="sr-only" />
                <i class="fa fa-map-marker-alt"></i> واحدها
            </label>
            <label class="btn btn-xs btn-ghost gap-1" 
                   :class="{ 'btn-primary': activeLayers.includes('hardware') }"
                   @click="toggleLayer('hardware')">
                <input type="checkbox" :checked="activeLayers.includes('hardware')" @change="toggleLayer('hardware')" class="sr-only" />
                <i class="fa fa-desktop"></i> سخت‌افزار
            </label>
            <label class="btn btn-xs btn-ghost gap-1" 
                   :class="{ 'btn-primary': activeLayers.includes('tickets') }"
                   @click="toggleLayer('tickets')">
                <input type="checkbox" :checked="activeLayers.includes('tickets')" @change="toggleLayer('tickets')" class="sr-only" />
                <i class="fa fa-ticket"></i> تیکت‌ها
            </label>
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
    <div x-show="false" x-data="{ unitId: null }" 
         @showUnitDetails.window="unitId = $event.detail.unitId; $nextTick(() => $refs.modal.showModal())">
        <dialog x-ref="modal" class="modal modal-bottom sm:modal-middle">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4">جزئیات واحد</h3>
                <div x-show="unitId" x-init="
                    @this('loadUnitDetails', unitId).then(r => {
                        this.unitDetails = r;
                    })
                ">
                    <div class="space-y-2" x-text="unitDetails"></div>
                </div>
                <div class="modal-action">
                    <form method="dialog"><button class="btn">بستن</button></form>
                </div>
            </div>
        </dialog>
    </div>
</div>

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
@endpush