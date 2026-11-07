/**
 * Map-dashboard Alpine component moved out of its `@script` block
 * (#1352, Step 3 / R3).
 *
 * WHY THIS EXISTS
 * ---------------
 * The `@script` body registered `Alpine.data('mapDashboard', ...)`
 * itself — and no `@script` body can execute under the CSP-safe
 * Alpine build (the CSP expression parser cannot parse Livewire's
 * IIFE wrapper), so the registration never happened and
 * `x-data="mapDashboard"` resolved to nothing. The factory moves
 * here verbatim and registers on `alpine:init` like every other
 * factory, with mechanical translations only:
 *
 * - Blade interpolations (`$mapToken`, `url('/api/gis')`, center,
 *   zoom, disease from/to) become `data-*` attributes on the
 *   x-data element, read in `init()` from `this.$el.dataset`.
 * - `@this('event', ...)` becomes `this.$wire('event', ...)`:
 *   `@this` compiles to `window.Livewire.find(id)`, and calling
 *   the returned proxy dispatches the event; `$wire` is that same
 *   proxy. No listener for these three events exists in the repo;
 *   the dispatch itself is preserved.
 *
 * Closure locals (`map`, `layerGroups`, `moveTimer`,
 * `diseasesPromise`) stay in the factory scope, one set per
 * element, exactly as the original closure had them. All methods,
 * the #1245/#1246 disease logic, the #1224 palette, the #1352
 * CSSOM diamond, and the popup escaping are byte-for-byte the
 * originals.
 */

export function registerMapDashboard(Alpine) {
    // Unit-details modal (#1383). The template's directive expressions are
    // reduced to bare identifiers — and the `showUnitDetails` listener lives in
    // init() — because the CSP-safe Alpine build's expression interpreter
    // rejects multi-statement bodies, `?.`, `??`, arrow functions and template
    // literals. Null-guarding and the server round-trip live here, in plain JS,
    // where that parser never runs.
    Alpine.data('unitDetailsModal', () => ({
        unitId: null,
        unitDetails: null,
        loading: false,

        init() {
            // The event name is camelCase (`showUnitDetails`) and HTML
            // lowercases attribute names, so an Alpine `@showUnitDetails.window`
            // directive can never match it (it listens for `showunitdetails`).
            // A plain window listener keeps the server's event name intact and
            // stays outside the CSP expression parser entirely.
            this.showUnitDetailsListener = (event) => {
                this.openUnitDetails(event.detail[0].unitId);
            };
            window.addEventListener('showUnitDetails', this.showUnitDetailsListener);
        },

        destroy() {
            window.removeEventListener('showUnitDetails', this.showUnitDetailsListener);
        },

        get unitName() {
            return this.unitDetails ? this.unitDetails.name : '';
        },

        get unitType() {
            return this.unitDetails ? this.unitDetails.type : '';
        },

        get unitLocation() {
            if (!this.unitDetails) return '';
            return (this.unitDetails.lat ?? '') + ', ' + (this.unitDetails.lng ?? '');
        },

        get unitChildrenCount() {
            return this.unitDetails ? this.unitDetails.children_count : '';
        },

        get unitError() {
            return this.unitDetails && this.unitDetails.error ? this.unitDetails.error : '';
        },

        openUnitDetails(unitId) {
            this.unitId = unitId;
            this.loading = true;
            this.unitDetails = null;

            const dialog = this.$refs.modal;
            if (dialog && !dialog.open) {
                this.$nextTick(() => dialog.showModal());
            }

            this.loadUnitDetails();
        },

        async loadUnitDetails() {
            if (!this.unitId) return;

            try {
                this.unitDetails = await this.$wire.loadUnitDetails(this.unitId);
            } catch (e) {
                // Client-only fallback: a rejected request is not the server's
                // 'Unit not found' contract, so it gets its own copy.
                this.unitDetails = { error: 'خطا در دریافت جزئیات واحد' };
            } finally {
                this.loading = false;
            }
        },
    }));

    Alpine.data('mapDashboard', () => {
        // The Leaflet instance and its layer groups live in closure locals,
        // never on the Alpine data object: Alpine proxies reactive data,
        // which breaks Leaflet's listener bookkeeping (issue #769). The map
        // itself is owned by the shared maps.map component
        // (Alpine.store('map')); this page only attaches its layers to it.
        let map = null;
        let layerGroups = {};
        let moveTimer = null;
        let diseasesPromise = null;

        // One definition of the region→unit boundary on the client. Mirrors
        // `DiseaseMapService::ZOOM_SWITCH`; `meta.zoom_switch` is authoritative
        // for the payload but this keeps the local branch from guessing.
        const DISEASE_ZOOM_SWITCH = 9;
        // Quantile classes, light → dark. `class`/`meta.breaks` come from
        // `/api/gis/disease-map` (#1245 decision ه) so the client never
        // re-derives quantiles and cannot disagree with the server.
        const DISEASE_COLORS = ['#ffffb2', '#fecc5c', '#fd8d3c', '#f03b20', '#bd0026'];
        const DISEASE_NO_DATA_COLOR = '#9ca3af';

        return {
            activeLayers: ['units'],
            filters: {
                hardware_type: '',
                ticket_priority: '',
                ticket_status: '',
            },
            stats: { units: 0, hardware: 0, open_tickets: 0 },
            currentBbox: null,
            apiToken: '',
            apiBase: '',
            center: [0, 0],
            zoom: 8,

            // Disease layer (#1246). `diseaseLevel` is 'region' | 'unit' | null.
            diseases: [],
            disease: { id: null, from: '', to: '' },
            diseaseError: '',
            diseaseLegend: [],
            diseaseBreaks: [],
            diseaseLevel: null,
            diseaseLoading: false,
            diseaseRequestId: 0,
            diseasesLoading: null,

            init() {
                // Server values arrive on data-* attributes of the
                // x-data element (see the template); read them before
                // the store callback uses center/zoom/token.
                const ds = this.$el.dataset;
                this.apiToken = ds.apiToken || '';
                this.apiBase = ds.apiBase || '';
                const lat = parseFloat(ds.centerLat ?? '');
                const lng = parseFloat(ds.centerLng ?? '');
                const zoom = parseFloat(ds.zoom ?? '');
                if (Number.isFinite(lat) && Number.isFinite(lng)) this.center = [lat, lng];
                if (Number.isFinite(zoom)) this.zoom = zoom;
                this.disease.from = ds.diseaseFrom || '';
                this.disease.to = ds.diseaseTo || '';

                window.Alpine.store('map').onReady((readyMap) => {
                    map = readyMap;
                    map.setView(this.center, this.zoom);

                    layerGroups = {
                        units: L.layerGroup().addTo(map),
                        hardware: L.layerGroup(),
                        tickets: L.layerGroup(),
                        // Never added to the map here: only when the layer is
                        // switched on. Cleared individually — see toggleLayer.
                        diseaseRegions: L.layerGroup(),
                        diseaseUnits: L.layerGroup(),
                    };

                    // Region below the marker pane (600), units above it, so a
                    // unit marker is never buried under a polygon.
                    map.createPane('diseaseRegionPane').style.zIndex = 410;
                    map.createPane('diseaseUnitPane').style.zIndex = 620;

                    ['diseaseRegionPane', 'diseaseUnitPane'].forEach((paneName) => {
                        const pane = map.getPane(paneName);
                        pane.style.transition = 'opacity 200ms ease';
                        pane.style.opacity = '0';
                        pane.style.pointerEvents = 'none';
                    });

                    map.on('moveend', () => this.onMapMove());

                    this.initDiseaseDatepickers();
                    this.onMapMove();
                });
            },

            initDiseaseDatepickers() {
                if (typeof jalaliDatepicker === 'undefined') return;

                jalaliDatepicker.startWatch({
                    time: false,
                    hasSecond: false,
                    format: 'YYYY/MM/DD',
                    separatorChars: { date: '/', between: ' ', time: ':' },
                });

                [
                    ['disease_date_from', 'from'],
                    ['disease_date_to', 'to'],
                ].forEach(([elementId, key]) => {
                    const input = document.getElementById(elementId);

                    // `wire:navigate` re-runs init against the same DOM node;
                    // rebinding would stack duplicate listeners.
                    if (!input || input.dataset.jdpBound === '1') return;

                    input.dataset.jdpBound = '1';
                    input.addEventListener('jdp:change', () => {
                        this.disease[key] = input.value;
                        this.loadDiseaseLayer(true);
                    });
                });
            },

            onMapMove() {
                if (!map) return;

                clearTimeout(moveTimer);

                moveTimer = setTimeout(() => {
                    const bounds = map.getBounds();
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
                    this.fetchAndRender(`${this.apiBase}/units?bbox=${bbox}`, layerGroups.units, 'unit', headers);
                }
                if (this.activeLayers.includes('hardware')) {
                    let url = `${this.apiBase}/hardware?bbox=${bbox}`;
                    if (this.filters.hardware_type) url += `&type=${this.filters.hardware_type}`;
                    this.fetchAndRender(url, layerGroups.hardware, 'hardware', headers);
                }
                if (this.activeLayers.includes('tickets')) {
                    let url = `${this.apiBase}/tickets?bbox=${bbox}`;
                    if (this.filters.ticket_priority) url += `&priority=${this.filters.ticket_priority}`;
                    if (this.filters.ticket_status) url += `&status=${this.filters.ticket_status}`;
                    this.fetchAndRender(url, layerGroups.tickets, 'ticket', headers);
                }
                // #1246: after the point layers, before the stats refresh. The
                // disease layer decides region/unit from the current zoom.
                if (this.activeLayers.includes('diseases')) {
                    this.loadDiseaseLayer();
                }

                // Load stats
                this.fetchStats(bbox, headers);
            },

            apiHeaders() {
                return {
                    'Authorization': 'Bearer ' + this.apiToken,
                    'Accept': 'application/json',
                };
            },

            async loadDiseases() {
                if (this.diseases.length > 0) return;
                if (diseasesPromise) return diseasesPromise;

                // Promise kept in a closure local: an Alpine-reactive Promise
                // is proxied, and every `await` would walk the proxy.
                diseasesPromise = (async () => {
                    try {
                        const response = await fetch(`${this.apiBase}/diseases`, { headers: this.apiHeaders() });
                        if (!response.ok) throw new Error(`diseases ${response.status}`);

                        const list = await response.json();
                        this.diseases = Array.isArray(list) ? list : [];

                        if (this.diseases.length === 0) {
                            this.diseaseError = 'بیماری فعالی تعریف نشده است.';

                            return;
                        }

                        this.diseaseError = '';

                        // Auto-select the first catalogue row: an empty map
                        // reads as "nothing to show", the selector stays as the
                        // escape hatch.
                        if (!this.disease.id) {
                            this.disease.id = this.diseases[0].id;
                            this.loadDiseaseLayer(true);
                        }
                    } catch (error) {
                        this.diseaseError = 'بارگذاری فهرست بیماری‌ها ناموفق بود.';
                    } finally {
                        diseasesPromise = null;
                    }
                })();

                return diseasesPromise;
            },

            async loadDiseaseLayer(force = false) {
                if (!map || !this.activeLayers.includes('diseases')) return;
                if (!this.disease.id) return;

                const level = map.getZoom() >= DISEASE_ZOOM_SWITCH ? 'unit' : 'region';

                // Region payloads are not bbox-filtered, so panning cannot
                // change the answer: refetch only on an explicit change
                // (disease/period/toggle). Unit mode refetches on every
                // `moveend` because its bbox did change.
                if (level === 'region' && this.diseaseLevel === 'region' && !force) return;

                const requestId = ++this.diseaseRequestId;
                this.diseaseLoading = true;

                const params = new URLSearchParams({
                    disease: this.disease.id,
                    from: this.disease.from,
                    to: this.disease.to,
                    zoom: String(Math.round(map.getZoom())),
                });

                // No `bbox` in region mode on purpose: the quantile must be
                // computed over every visible region, not just the viewport.
                if (level === 'unit' && this.currentBbox) params.set('bbox', this.currentBbox);

                try {
                    const response = await fetch(`${this.apiBase}/disease-map?${params}`, { headers: this.apiHeaders() });
                    if (!response.ok) throw new Error(`disease-map ${response.status}`);

                    const data = await response.json();

                    // A fast pan can land two responses out of order; only the
                    // newest request may touch the layers.
                    if (requestId !== this.diseaseRequestId) return;

                    if (data.type !== 'FeatureCollection' || !Array.isArray(data.features)) {
                        throw new Error('قرارداد لایه بیماری نامعتبر است.');
                    }

                    // #1245 ships `county`/`province` below the switch and
                    // `unit` above it. Anything else means the contract moved;
                    // render an error instead of mis-reading a payload.
                    if (data.features.length > 0 && !this.featuresMatchLevel(data.features, level)) {
                        throw new Error('قرارداد لایه بیماری نامعتبر است.');
                    }

                    this.diseaseError = '';
                    this.applyDiseaseLevel(level, data.features, data.meta || {});
                } catch (error) {
                    if (requestId !== this.diseaseRequestId) return;

                    // Keep the previous level on screen: a failed refresh must
                    // not blank the map.
                    this.diseaseError = error.message.startsWith('disease-map')
                        ? 'بارگذاری لایه بیماری ناموفق بود.'
                        : error.message;
                } finally {
                    if (requestId === this.diseaseRequestId) this.diseaseLoading = false;
                }
            },

            featuresMatchLevel(features, level) {
                return features.every((feature) => {
                    const featureLevel = feature?.properties?.level;

                    return level === 'unit'
                        ? featureLevel === 'unit'
                        : featureLevel === 'county' || featureLevel === 'province';
                });
            },

            applyDiseaseLevel(level, features, meta) {
                const isUnit = level === 'unit';
                const targetGroup = isUnit ? layerGroups.diseaseUnits : layerGroups.diseaseRegions;
                const sourceGroup = isUnit ? layerGroups.diseaseRegions : layerGroups.diseaseUnits;
                const targetPaneName = isUnit ? 'diseaseUnitPane' : 'diseaseRegionPane';
                const sourcePaneName = isUnit ? 'diseaseRegionPane' : 'diseaseUnitPane';

                const levelChanged = this.diseaseLevel !== level;

                targetGroup.clearLayers();
                this.renderDiseaseFeatures(features, level, meta);
                this.diseaseLevel = level;

                if (!map) return;

                const target = map.getPane(targetPaneName);
                const source = map.getPane(sourcePaneName);

                if (!target || !source) return;

                if (!levelChanged) {
                    target.style.opacity = '1';
                    target.style.pointerEvents = '';

                    return;
                }

                // Crossfade between the two panes; the hidden pane must not
                // swallow clicks meant for the visible one.
                const requestId = this.diseaseRequestId;
                source.style.pointerEvents = 'none';
                target.style.opacity = '0';

                requestAnimationFrame(() => {
                    target.style.opacity = '1';
                    target.style.pointerEvents = '';
                    source.style.opacity = '0';

                    setTimeout(() => {
                        if (this.diseaseRequestId === requestId) sourceGroup.clearLayers();
                    }, 220);
                });
            },

            renderDiseaseFeatures(features, level, meta) {
                if (features.length === 0) {
                    this.diseaseLegend = [];
                    this.diseaseBreaks = [];
                    this.diseaseError = 'در این بازه یا محدوده، داده‌ای ثبت نشده است.';

                    return;
                }

                if (level === 'unit') {
                    this.diseaseLegend = [];
                    this.diseaseBreaks = [];

                    features.forEach((feature) => {
                        if (feature?.geometry?.type !== 'Point') return;

                        const [lng, lat] = feature.geometry.coordinates;
                        const latNumber = Number(lat);
                        const lngNumber = Number(lng);

                        if (!Number.isFinite(latNumber) || !Number.isFinite(lngNumber)) return;

                        // Every base unit in scope gets a marker, `cases = 0`
                        // included (decision 14) — an absent marker would read
                        // as "unit does not exist".
                        L.circleMarker([latNumber, lngNumber], {
                            pane: 'diseaseUnitPane',
                            className: 'disease-unit-marker',
                            radius: 7,
                            color: '#ffffff',
                            weight: 2,
                            opacity: 1,
                            fillColor: '#2563eb',
                            fillOpacity: 1,
                        })
                            .bindPopup(() => this.diseaseUnitPopup(feature.properties))
                            .addTo(layerGroups.diseaseUnits);
                    });

                    return;
                }

                // Server-side quantile (decision ه in #1245). The client reads
                // `class` per feature and `breaks` for the legend, so there is
                // one quantile implementation, not two.
                const breaks = Array.isArray(meta.breaks)
                    ? meta.breaks.map((value) => this.toRate(value)).filter((value) => value !== null)
                    : [];

                this.diseaseBreaks = breaks;
                this.diseaseLegend = this.buildDiseaseLegend(breaks, features);

                const geoLayer = L.geoJSON({ type: 'FeatureCollection', features }, {
                    pane: 'diseaseRegionPane',
                    style: (feature) => {
                        const rate = this.toRate(feature.properties.rate);

                        return {
                            color: '#ffffff',
                            weight: 1,
                            opacity: 0.9,
                            fillColor: rate === null
                                ? DISEASE_NO_DATA_COLOR
                                : this.diseaseColor(feature.properties.class),
                            // Grey polygons stay visible but visibly "unknown".
                            fillOpacity: rate === null ? 0.35 : 0.75,
                        };
                    },
                    onEachFeature: (feature, polygon) => {
                        polygon.bindPopup(() => this.diseaseRegionPopup(feature.properties));
                    },
                });

                layerGroups.diseaseRegions.addLayer(geoLayer);
            },

            buildDiseaseLegend(breaks, features) {
                const hasRate = features.some((feature) => this.toRate(feature.properties.rate) !== null);

                if (!hasRate) {
                    return [{
                        color: DISEASE_NO_DATA_COLOR,
                        label: 'نرخ قابل محاسبه نیست (جمعیت ثبت نشده)',
                    }];
                }

                if (breaks.length === 0) return [];

                const rows = breaks.map((upper, index) => ({
                    color: DISEASE_COLORS[Math.min(index, DISEASE_COLORS.length - 1)],
                    label: index === 0
                        ? `≤ ${this.fa(upper, 1)}`
                        : `${this.fa(breaks[index - 1], 1)} – ${this.fa(upper, 1)}`,
                }));

                rows.push({
                    color: DISEASE_COLORS[Math.min(rows.length, DISEASE_COLORS.length - 1)],
                    label: `> ${this.fa(breaks[breaks.length - 1], 1)}`,
                });

                return rows.slice(0, DISEASE_COLORS.length);
            },

            diseaseColor(classIndex) {
                const index = Number.isInteger(Number(classIndex)) ? Number(classIndex) : 0;

                return DISEASE_COLORS[Math.min(Math.max(index, 0), DISEASE_COLORS.length - 1)];
            },

            toRate(value) {
                if (value === null || value === undefined || value === '' || value === '—') return null;

                const number = Number(value);

                return Number.isFinite(number) ? number : null;
            },

            fa(value, digits = 0) {
                return Number(value).toLocaleString('fa-IR', {
                    minimumFractionDigits: digits,
                    maximumFractionDigits: digits,
                });
            },

            escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value ?? '';

                return div.innerHTML;
            },

            diseaseRegionPopup(props) {
                const rate = this.toRate(props.rate);

                return `
                    <div dir="rtl" class="map-popup">
                        <strong>${this.escapeHtml(props.name)}</strong><br>
                        موارد: ${this.fa(Number(props.cases) || 0, 0)}<br>
                        جمعیت: ${props.population == null ? '—' : this.fa(Number(props.population), 0)}<br>
                        نرخ: ${rate === null ? '—' : this.fa(rate, 1) + ' در ۱۰٬۰۰۰'}
                    </div>`;
            },

            diseaseUnitPopup(props) {
                return `
                    <div dir="rtl" class="map-popup-narrow">
                        <strong>${this.escapeHtml(props.name)}</strong><br>
                        <span class="badge badge-primary badge-sm">${this.fa(Number(props.cases) || 0, 0)} مورد</span>
                    </div>`;
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

                    if (type === 'unit' || type === 'hardware') {
                        // Perf: one lightweight SVG shape per point instead of a
                        // divIcon DOM node + inner div (same look: white ring,
                        // filled dot; class kept so the e2e count still works).
                        marker = L.circleMarker([lat, lng], {
                            className: type === 'unit' ? 'unit-marker' : 'hardware-marker',
                            radius: type === 'unit' ? 8 : 6,
                            color: '#ffffff',
                            weight: 2,
                            opacity: 1,
                            fillColor: iconColor,
                            fillOpacity: 1,
                        });
                    } else if (type === 'ticket') {
                        // Issue #1352: the diamond is a real element — Leaflet
                        // 1.9.4 appends an HTMLElement `html` — so its dynamic
                        // background is set via CSSOM, not a `style=` string.
                        const diamond = document.createElement('div');
                        diamond.className = 'map-marker-diamond';
                        diamond.style.background = iconColor;
                        marker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'ticket-marker',
                                html: diamond,
                                iconSize: [12, 12],
                            })
                        });
                    }

                    if (marker) {
                        // Lazy: popup HTML is only built when a point is opened.
                        marker.bindPopup(() => this.createPopup(type, props));
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
                        <div dir="rtl" class="map-popup">
                            <strong>${escapeHtml(props.name)}</strong><br>
                            نوع: ${escapeHtml(this.getUnitTypeLabel(props.unit_type_id))}<br>
                            موقعیت: ${props.lat?.toFixed(6)}, ${props.lng?.toFixed(6)}
                        </div>`;
                } else if (type === 'hardware') {
                    return `
                        <div dir="rtl" class="map-popup">
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
                        <div dir="rtl" class="map-popup">
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
                    this.$wire.dispatch('unitSelected', [props.id]);
                }
            },

            toggleLayer(layer) {
                const isDisease = layer === 'diseases';

                if (this.activeLayers.includes(layer)) {
                    this.activeLayers = this.activeLayers.filter(l => l !== layer);

                    if (isDisease) {
                        // Two sibling groups, cleared explicitly:
                        // `LayerGroup.clearLayers()` is not recursive, and a
                        // parent group would only detach its children.
                        layerGroups.diseaseRegions?.clearLayers();
                        layerGroups.diseaseUnits?.clearLayers();

                        if (map) {
                            [layerGroups.diseaseRegions, layerGroups.diseaseUnits].forEach((group) => {
                                if (group && map.hasLayer(group)) map.removeLayer(group);
                            });

                            ['diseaseRegionPane', 'diseaseUnitPane'].forEach((paneName) => {
                                const pane = map.getPane(paneName);

                                if (!pane) return;

                                pane.style.opacity = '0';
                                pane.style.pointerEvents = 'none';
                            });
                        }

                        this.diseaseLevel = null;
                        this.diseaseLegend = [];
                        this.diseaseBreaks = [];
                        this.diseaseError = '';
                        // Discard any response still in flight: re-enabling the
                        // layer must not show the old period's data.
                        this.diseaseRequestId += 1;
                    } else {
                        layerGroups[layer]?.clearLayers();
                        if (map && layerGroups[layer]) {
                            map.removeLayer(layerGroups[layer]);
                        }
                    }
                } else {
                    this.activeLayers.push(layer);

                    if (isDisease) {
                        if (map) {
                            layerGroups.diseaseRegions.addTo(map);
                            layerGroups.diseaseUnits.addTo(map);
                        }

                        // Catalogue is fetched once; the map fetch follows it.
                        this.loadDiseases();
                    } else if (map && layerGroups[layer] && !map.hasLayer(layerGroups[layer])) {
                        layerGroups[layer].addTo(map);
                    }

                    this.loadLayers();
                }
                this.$wire('layerToggled', layer);
            },

            setFilter(key, value) {
                this.filters[key] = value;
                this.$wire('filterChanged', this.filters);
                this.loadLayers();
            },
        };
    });
}
