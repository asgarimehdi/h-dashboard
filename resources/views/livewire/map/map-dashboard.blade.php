<?php
use App\Models\Ticket;
use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
return new class extends Component
{
    /**
     * Name of the personal access token this page mints for its own fetches.
     * `POST /logout` revokes exactly this name, and nothing else.
     */
    public const TOKEN_NAME = 'map-dashboard';

    /**
     * The page only reads `/api/gis/*` (`routes/api.php`, gated by
     * `ability:gis:read` + `role_or_permission:map`), so `gis:read` is the
     * whole surface. Never fall back to Sanctum's `['*']` default here: a
     * wildcard token passes every `abilities:*write` gate (issue #840).
     */
    public const TOKEN_ABILITIES = ['gis:read'];

    /**
     * Lifetime of the in-page token. The plaintext lives in the rendered HTML,
     * so it is deliberately short-lived; `config/sanctum.php` expiration is
     * 24h, which is far too long for a value embedded in a page.
     */
    public const TOKEN_TTL_MINUTES = 60;

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

    /**
     * Bearer token for the page's own `/api/gis/*` fetches.
     *
     * `#[Locked]` is defence in depth, not the mitigation: the property is
     * written server-side by `mount()`/`resolveToken()`, and the plaintext is
     * unavoidably in the rendered HTML (the Alpine factory needs it). What
     * actually bounds the damage is `TOKEN_ABILITIES` + `TOKEN_TTL_MINUTES`.
     */
    #[Locked]
    public $mapToken = '';

    /**
     * Disease layer period (#1246). Jalali `Y/m/d`, both ends inclusive.
     *
     * Defaults to the current Persian year (1 Farvardin → today), computed in
     * `mount()` with the calendar, never hard-coded. The server re-parses and
     * validates these; they are display defaults, not an authorization input.
     */
    public string $diseaseFrom = '';

    public string $diseaseTo = '';

    protected $listeners = [
        'mapMoved' => 'onMapMoved',
        'layerToggled' => 'onLayerToggled',
        'filterChanged' => 'onFilterChanged',
        'unitSelected' => 'onUnitSelected',
    ];

    public function mount()
    {
        $this->resolveToken();

        $this->diseaseFrom = \Morilog\Jalali\Jalalian::now()->getYear().'/01/01';
        $this->diseaseTo = \Morilog\Jalali\Jalalian::now()->format('Y/m/d');
    }

    /**
     * Reuse a still-valid map token instead of minting a new one per page load.
     *
     * Two problems this removes (issue #840):
     *  - every `F5` created a fresh row plus a fresh plaintext in the HTML, so
     *    a wildcard/`gis:read` token was produced on each render;
     *  - the old code deleted the previous token BEFORE creating the new one,
     *    so a user with two tabs had the first tab's token revoked out from
     *    under it and its layers started failing.
     *
     * Sanctum stores only the hash, so an existing token can never yield its
     * plaintext again — that is why the plaintext is kept in the encrypted
     * session and re-read on later requests.
     */
    public function resolveToken(): string
    {
        if ($this->mapToken !== '') {
            return $this->mapToken;
        }

        $user = auth()->user();
        $sessionKey = 'map_dashboard_token_'.$user->getAuthIdentifier();

        // Drop expired/rotated leftovers for this page, never a live token
        // belonging to another open tab.
        $user->tokens()
            ->where('name', self::TOKEN_NAME)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<=', now()))
            ->delete();

        if (($cached = session($sessionKey)) && $this->tokenIsLive($user, $cached)) {
            $this->mapToken = $cached;

            return $cached;
        }

        session()->forget($sessionKey);

        $this->mapToken = $user->createToken(
            self::TOKEN_NAME,
            self::TOKEN_ABILITIES,
            now()->addMinutes(self::TOKEN_TTL_MINUTES),
        )->plainTextToken;

        session([$sessionKey => $this->mapToken]);

        return $this->mapToken;
    }

    /**
     * Options for the ticket-priority filter (#954).
     *
     * Built from `Ticket::PRIORITIES` — the canonical app vocabulary — so the
     * filter can never offer a value (e.g. the DB-only `medium`/`high`) that
     * no report bucket reads. The "all" option (empty value) must stay first.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function priorityOptions(): array
    {
        // Partial by design: a value added to Ticket::PRIORITIES without a
        // Persian label falls back to the raw identifier below.
        /** @var array<string, string> $labels */
        $labels = ['low' => 'پایین', 'normal' => 'عادی', 'urgent' => 'فوری'];

        return [
            ['value' => '', 'label' => 'همه اولویت‌ها'],
            ...array_map(
                fn (string $priority): array => [
                    'value' => $priority,
                    'label' => $labels[$priority] ?? $priority,
                ],
                Ticket::PRIORITIES,
            ),
        ];
    }

    /**
     * Is the plaintext we kept in the session still backed by a usable row?
     * Returns false when the row is gone, expired, or was revoked elsewhere.
     */
    private function tokenIsLive(User $user, string $plainTextToken): bool
    {
        [$id] = array_pad(explode('|', $plainTextToken, 2), 1, '');

        if ($id === '' || ! ctype_digit($id)) {
            return false;
        }

        // Expiry is checked in SQL rather than through `$token->expires_at`:
        // Sanctum's PersonalAccessToken ships no `@property` annotations, so a
        // property read there is a PHPStan error for a cast that does exist.
        return $user->tokens()
            ->whereKey($id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    public function onMapMoved($data)
    {
        $this->mapCenterLat = $data['center'][0] ?? $this->mapCenterLat;
        $this->mapCenterLng = $data['center'][1] ?? $this->mapCenterLng;
        $this->mapZoom = $data['zoom'] ?? $this->mapZoom;
        $this->bbox = $data['bbox'] ?? $this->bbox;
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

    /** @var array<string, string> Explicit allowlist of client keys → component properties. */
    private const FILTER_PROPERTY_MAP = [
        'hardware_type' => 'filterHardware',
        'ticket_priority' => 'filterPriority',
        'ticket_status' => 'filterStatus',
    ];

    public function onFilterChanged($filters)
    {
        foreach ($filters as $key => $value) {
            $property = self::FILTER_PROPERTY_MAP[$key] ?? null;
            if ($property !== null) {
                $this->$property = $value;
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

        if (! in_array($unitId, $accessibleIds)) {
            return ['error' => 'Unit not found'];
        }

        $unit = \App\Models\Unit::with(['children', 'unitType'])->withCount('children')->find($unitId);
        if (! $unit) {
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
};
?>

<div
    wire:ignore
    id="map-container"
    class="w-full relative"
    x-data="mapDashboard"
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
            {{-- #1246: aggregate-only disease layer. Same `map` gate as the rest
                 of this page — `/api/gis/disease*` carries counts/rates, never
                 patient data (decision 18). --}}
            <button type="button" id="disease-layer-toggle"
                   class="btn btn-xs btn-ghost gap-1"
                   :class="{ 'btn-primary': activeLayers.includes('diseases') }"
                   @click="toggleLayer('diseases')">
                <i class="fa fa-virus"></i> بیماری‌ها
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
                @foreach ($this->priorityOptions() as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
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

        {{-- #1246: disease controls. Hidden until the layer is on; the row is
             full-width so `flex-wrap` pushes it onto a second line instead of
             squeezing the layer buttons. All state is Alpine — this panel does
             no server round-trip. --}}
        <div id="disease-controls"
             x-show="activeLayers.includes('diseases')"
             class="w-full flex flex-wrap items-end gap-2 border-t border-base-300 pt-2"
             style="display: none;">
            <select id="disease-select"
                    x-model.number="disease.id"
                    @change="$nextTick(() => loadDiseaseLayer(true))"
                    class="select select-sm w-48">
                <option value="">انتخاب بیماری</option>
                <template x-for="d in diseases" :key="d.id">
                    <option :value="d.id" x-text="d.name_fa"></option>
                </template>
            </select>

            <input id="disease_date_from" data-jdp readonly value="{{ $diseaseFrom }}"
                   class="input input-sm w-32" aria-label="از تاریخ" />
            <input id="disease_date_to" data-jdp readonly value="{{ $diseaseTo }}"
                   class="input input-sm w-32" aria-label="تا تاریخ" />

            <span x-show="diseaseLoading" class="loading loading-spinner loading-xs"></span>
            <span id="disease-error" x-show="diseaseError" class="text-error text-xs"
                  x-text="diseaseError"></span>

            <div id="disease-legend"
                 x-show="diseaseLevel === 'region' && diseaseLegend.length"
                 class="flex flex-wrap items-center gap-2 text-xs">
                <template x-for="row in diseaseLegend" :key="row.label">
                    <span class="flex items-center gap-1">
                        <span class="inline-block w-3 h-3 rounded"
                              :style="`background:${row.color}`"></span>
                        <span x-text="row.label"></span>
                    </span>
                </template>
                <span class="text-base-content/60">نرخ در هر ۱۰٬۰۰۰ نفر</span>
            </div>
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

    <!-- Map Element: the shared maps.map component owns the Leaflet instance
         (Alpine.store('map')); this page only attaches its layers via onReady. -->
    <livewire:maps.map wire:key="map-dashboard-page-map" />

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

    <script nonce="{{ Vite::cspNonce() }}">
        function loadUnitDetails() {
            if (!this.unitId) return;
            @this('loadUnitDetails', this.unitId).then(r => {
                this.loading = false;
                this.unitDetails = r;
            });
        }
    </script>
</div>

@assets
    {{-- Issue #1182: Leaflet core is page-specific now and ships on the HOST page
         — never inside `maps/map.blade.php`, whose own asset would arrive with the
         child component, after this page's Alpine `init()` had already asked the
         store for a map. `@assets`, not `@push('scripts')`: a pushed tag lands at
         the end of the body, and on `wire:navigate` Livewire morphs the body and
         runs Alpine BEFORE evaluating those scripts — measured `L is not defined`
         and a blank map (#028). Livewire injects `@assets` into <head> and
         evaluates new head assets before the navigation completes. --}}
    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
@endassets

@assets
    {{-- #1246: the disease period pickers. Same `@assets` rationale as Leaflet
         above — `jalalidatepicker` must exist before this page's Alpine
         `init()` starts watching, and a pushed tag would arrive after Alpine
         on `wire:navigate`. The CSS is global (`layouts/app.blade.php`). --}}
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
@endassets

@script
<script>
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
            apiToken: '{{ $mapToken }}',
            apiBase: '{{ url('/api/gis') }}',
            center: [{{ $mapCenterLat }}, {{ $mapCenterLng }}],
            zoom: {{ $mapZoom }},

            // Disease layer (#1246). `diseaseLevel` is 'region' | 'unit' | null.
            diseases: [],
            disease: { id: null, from: '{{ $diseaseFrom }}', to: '{{ $diseaseTo }}' },
            diseaseError: '',
            diseaseLegend: [],
            diseaseBreaks: [],
            diseaseLevel: null,
            diseaseLoading: false,
            diseaseRequestId: 0,
            diseasesLoading: null,

            init() {
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
                    <div dir="rtl" style="min-width:200px;font-family:inherit;">
                        <strong>${this.escapeHtml(props.name)}</strong><br>
                        موارد: ${this.fa(Number(props.cases) || 0, 0)}<br>
                        جمعیت: ${props.population == null ? '—' : this.fa(Number(props.population), 0)}<br>
                        نرخ: ${rate === null ? '—' : this.fa(rate, 1) + ' در ۱۰٬۰۰۰'}
                    </div>`;
            },

            diseaseUnitPopup(props) {
                return `
                    <div dir="rtl" style="min-width:160px;font-family:inherit;">
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
                        marker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                className: 'ticket-marker',
                                html: `<div style="width:12px;height:12px;transform:rotate(45deg);background:${iconColor};border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>`,
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
                @this('layerToggled', layer);
            },

            setFilter(key, value) {
                this.filters[key] = value;
                @this('filterChanged', this.filters);
                this.loadLayers();
            },
        };
    });
</script>
@endscript
