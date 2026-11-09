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
    data-api-token="{{ $mapToken }}"
    data-api-base="{{ url('/api/gis') }}"
    data-center-lat="{{ $mapCenterLat }}"
    data-center-lng="{{ $mapCenterLng }}"
    data-zoom="{{ $mapZoom }}"
    data-disease-from="{{ $diseaseFrom }}"
    data-disease-to="{{ $diseaseTo }}"
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
             x-cloak
             class="w-full flex flex-wrap items-end gap-2 border-t border-base-300 pt-2">
            <select id="disease-select"
                    x-model.number="disease.id"
                    @change="loadDiseaseLayer(true)"
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
                              :style="{ background: row.color }"></span>
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
    <div x-data="unitDetailsModal">
        <dialog x-ref="modal" class="modal modal-bottom sm:modal-middle">
            <div class="modal-box">
                <h3 class="font-bold text-lg mb-4">جزئیات واحد</h3>
                <div x-show="loading" class="loading loading-spinner loading-lg"></div>
                <div x-show="!loading && unitDetails" class="space-y-2">
                    <div><strong>نام:</strong> <span x-text="unitName"></span></div>
                    <div><strong>نوع:</strong> <span x-text="unitType"></span></div>
                    <div><strong>موقعیت:</strong> <span x-text="unitLocation"></span></div>
                    <div><strong>زیرمجموعه‌ها:</strong> <span x-text="unitChildrenCount"></span></div>
                </div>
                <div x-show="!loading && unitDetails && unitDetails.error" class="text-error" x-text="unitError"></div>
                <div class="modal-action mt-4">
                    <form method="dialog"><button class="btn">بستن</button></form>
                </div>
            </div>
        </dialog>
    </div>

{{-- Issue #1352: popup/marker geometry + the `x-cloak` hide rule live in this
     nonce'd block instead of `style=` attributes (popup strings, the ticket
     marker, and the initially-hidden disease panel). The `:style` legend
     binding below is a CSSOM assignment, not a style attribute, and stays. --}}
<style nonce="{{ Vite::cspNonce() }}">
    [x-cloak] { display: none !important; }
    .map-popup { min-width: 200px; font-family: inherit; }
    .map-popup-narrow { min-width: 160px; font-family: inherit; }
    .map-marker-diamond { width: 12px; height: 12px; transform: rotate(45deg); border: 2px solid white; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3); }
</style>

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

