<?php

use App\Models\Hardware;
use App\Models\NetworkLink;
use App\Services\AccessService;
use Livewire\Component;
use Illuminate\Support\Facades\Cache;

return new class extends Component
{
    public bool $showHelpModal = false;

    public array $switches = [];
    public array $links = [];
    public array $vlans = [];
    public array $spof = [];

    public bool $showSwitches = true;
    public bool $showLinks = true;
    public bool $showVlans = false;
    public bool $showDevices = false;
    public bool $showSpof = false;

    public string $selectedLinkType = '';
    public string $selectedVlan = '';
    public array $stats = [];
    public bool $loading = false;

    public function mount(): void
    {
        $this->loadData();
        $this->loadStats();
    }

    public function loadData(): void
    {
        $this->loading = true;
        $user = auth()->user();
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($user);

        // Load switches
        $switches = Hardware::with('person.unit')
            ->whereHas('person', function ($q) use ($accessibleIds) {
                $q->whereIn('u_id', $accessibleIds);
            })
            ->whereNotNull('switch')
            ->where('switch', '!=', '')
            ->get();

        $this->switches = $switches->groupBy('switch')->map(function ($group) {
            $first = $group->first();
            $unit = $first->person->unit ?? null;

            return [
                'name' => $group->first()->switch,
                'lat' => $unit->lat ?? null,
                'lng' => $unit->lng ?? null,
                'unit_id' => $unit->id ?? null,
                'unit_name' => $unit->name ?? null,
                'device_count' => $group->count(),
                'vlans' => $group->pluck('vlan')->filter()->unique()->values(),
            ];
        })->values()->toArray();

        // Load links from network_links table
        $this->links = NetworkLink::with(['sourceUnit', 'targetUnit'])
            ->where(function ($q) use ($accessibleIds) {
                $q->whereIn('source_unit_id', $accessibleIds)
                    ->orWhereIn('target_unit_id', $accessibleIds);
            })
            ->get()
            ->map(function ($link) {
                return [
                    'id' => $link->id,
                    'source_switch' => $link->source_switch,
                    'target_switch' => $link->target_switch,
                    'link_type' => $link->link_type,
                    'vlans' => $link->vlans ?? [],
                    'distance_km' => $link->distance_km,
                    'latency_ms' => $link->latency_ms,
                    'bandwidth_mbps' => $link->bandwidth_mbps,
                    'is_redundant' => $link->is_redundant,
                    'source_lat' => $link->sourceUnit->lat ?? null,
                    'source_lng' => $link->sourceUnit->lng ?? null,
                    'target_lat' => $link->targetUnit->lat ?? null,
                    'target_lng' => $link->targetUnit->lng ?? null,
                ];
            })->toArray();

        // Load VLANs summary
        $vlans = Hardware::whereHas('person', function ($q) use ($accessibleIds) {
            $q->whereIn('u_id', $accessibleIds);
        })
            ->whereNotNull('vlan')
            ->where('vlan', '!=', '')
            ->pluck('vlan')
            ->countBy();

        $vlanColors = ['1' => '#e74c3c', '10' => '#3498db', '20' => '#2ecc71', '30' => '#f39c12', '40' => '#9b59b6', '50' => '#1abc9c', '100' => '#e67e22', '200' => '#34495e'];
        $this->vlans = $vlans->map(fn($count, $vlan) => [
            'vlan' => (string) $vlan,
            'count' => $count,
            'color' => $vlanColors[$vlan] ?? '#' . substr(md5($vlan), 0, 6),
        ])->values()->toArray();

        // SPOF - switches serving many units
        $this->spof = $switches->groupBy('switch')->filter(fn($group) => $group->pluck('person.u_id')->unique()->count() >= 3)
            ->map(function ($group) {
                $first = $group->first();
                $unit = $first->person->unit ?? null;
                $unitCount = $group->pluck('person.u_id')->unique()->count();
                return [
                    'name' => $group->first()->switch,
                    'lat' => $unit->lat ?? null,
                    'lng' => $unit->lng ?? null,
                    'unit_name' => $unit->name ?? null,
                    'unit_count' => $unitCount,
                    'risk_score' => $unitCount * 10 + $group->count(),
                ];
            })
            ->sortByDesc('risk_score')
            ->values()->toArray();

        $this->loading = false;

        $this->dispatch('network-map-data-loaded', [
            'switches' => $this->switches,
            'links' => $this->links,
            'vlans' => $this->vlans,
            'spof' => $this->spof,
        ]);
    }

    public function loadStats(): void
    {
        $this->stats = [
            'total_switches' => count($this->switches),
            'total_links' => count($this->links),
            'total_vlans' => count($this->vlans),
            'total_devices' => Hardware::whereHas('person', function ($q) {
                $accessibleIds = app(AccessService::class)->accessibleUnitIds();
                $q->whereIn('u_id', $accessibleIds);
            })->count(),
            'spof_count' => count($this->spof),
        ];
    }

    public function getLinkTypeLabel(string $type): string
    {
        return match($type) {
            'fiber' => 'فایبر نوری',
            'wireless' => 'بی‌سیم',
            'mpls' => 'MPLS',
            'vpn' => 'VPN',
            default => 'نامشخص',
        };
    }
};
?>

<div>
    <x-header title="نقشه تاپولوژی شبکه" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="maps" wireModel="showHelpModal" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    {{-- Stats bar --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4">
        <div class="stat bg-base-200 rounded-box p-3 shadow">
            <div class="stat-title text-xs">سوئیچ‌ها</div>
            <div class="stat-value text-lg">{{ $stats['total_switches'] ?? 0 }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box p-3 shadow">
            <div class="stat-title text-xs">لینک‌ها</div>
            <div class="stat-value text-lg">{{ $stats['total_links'] ?? 0 }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box p-3 shadow">
            <div class="stat-title text-xs">VLANها</div>
            <div class="stat-value text-lg">{{ $stats['total_vlans'] ?? 0 }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box p-3 shadow">
            <div class="stat-title text-xs">تجهیزات</div>
            <div class="stat-value text-lg">{{ $stats['total_devices'] ?? 0 }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box p-3 shadow">
            <div class="stat-title text-xs">نقاط شکست</div>
            <div class="stat-value text-lg text-error">{{ $stats['spof_count'] ?? 0 }}</div>
        </div>
    </div>

    {{-- Layer controls --}}
    <x-card shadow class="mb-4 p-3">
        <div class="flex flex-wrap items-center gap-4">
            <x-toggle right wire:model.live="showSwitches" label="سوئیچ‌ها" />
            <x-toggle right wire:model.live="showLinks" label="لینک‌ها" />
            <x-toggle right wire:model.live="showVlans" label="VLANها" />
            <x-toggle right wire:model.live="showDevices" label="تجهیزات" />
            <x-toggle right wire:model.live="showSpof" label="نقاط شکست" />

            <div class="divider divider-horizontal"></div>

            {{-- Link type filter --}}
            <x-select
                wire:model.live="selectedLinkType"
                label="نوع لینک"
                :options="['' => 'همه', 'fiber' => 'فایبر', 'wireless' => 'بی‌سیم', 'mpls' => 'MPLS', 'vpn' => 'VPN']"
                class="w-32"
            />

            <x-button wire:click="loadData" label="بروزرسانی" icon="arrow-path" class="btn-sm btn-primary" />
        </div>
    </x-card>

    {{-- Map container --}}
    <x-card shadow class="p-0">
        <div class="relative" style="min-height: 600px;">
            <div id="network-map" style="width: 100%; height: 600px;"></div>

            {{-- Legend sidebar --}}
            <div class="absolute top-4 right-4 z-10 w-64 space-y-2" x-data="{ open: true }">
                <button class="btn btn-sm btn-circle btn-ghost bg-base-100/80 shadow" @click="open = !open">
                    <x-heroicon-o-bars-3 />
                </button>
                <div x-show="open" x-transition class="bg-base-100/90 backdrop-blur rounded-box shadow p-3 space-y-3 max-h-[500px] overflow-y-auto">
                    {{-- VLAN Legend --}}
                    @if ($showVlans && count($vlans) > 0)
                        <div>
                            <h4 class="font-bold text-sm">VLANها</h4>
                            @foreach ($vlans as $v)
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-3 h-3 rounded-full" style="background: {{ $v['color'] }}"></span>
                                    <span>VLAN {{ $v['vlan'] }} ({{ $v['count'] }} دستگاه)</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- SPOF List --}}
                    @if ($showSpof && count($spof) > 0)
                        <div>
                            <h4 class="font-bold text-sm text-error">نقاط شکست</h4>
                            @foreach ($spof as $s)
                                <div class="text-xs bg-error/10 rounded p-1 mb-1">
                                    <span class="font-bold">{{ $s['name'] }}</span>
                                    <span class="text-error">({{ $s['unit_count'] }} واحد)</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-card>
</div>

@assets
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    .network-map-tooltip {
        background: var(--b1);
        color: var(--bc);
        padding: 8px 12px;
        border-radius: 8px;
        border: 1px solid var(--b3);
        font-size: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .network-map-tooltip .font-bold { font-weight: 700; }
</style>
@endassets

@script
<script>
    var map;
    var layers = {
        switches: [],
        links: [],
        devices: [],
        spof: [],
    };

    function initMap() {
        if (map) return;
        map = L.map('network-map', { zoomControl: true }).setView([35.5, 48.0], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 18,
        }).addTo(map);
    }

    function clearLayer(type) {
        layers[type].forEach(function(layer) { map.removeLayer(layer); });
        layers[type] = [];
    }

    function renderSwitches(switches) {
        clearLayer('switches');
        if (!@js($showSwitches)) return;
        switches.forEach(function(sw) {
            if (!sw.lat || !sw.lng) return;
            var marker = L.circleMarker([sw.lat, sw.lng], {
                radius: 6, fillColor: '#3b82f6', color: '#1e40af', weight: 2, fillOpacity: 0.8,
            }).addTo(map);
            marker.bindTooltip(
                '<div class="network-map-tooltip">' +
                '<div class="font-bold">' + sw.name + '</div>' +
                '<div>واحد: ' + (sw.unit_name || '-') + '</div>' +
                '<div>دستگاه‌ها: ' + sw.device_count + '</div>' +
                '<div>VLAN: ' + (sw.vlans.length > 0 ? sw.vlans.join(', ') : '-') + '</div>' +
                '</div>'
            );
            layers.switches.push(marker);
        });
    }

    function renderLinks(links) {
        clearLayer('links');
        if (!@js($showLinks)) return;
        var linkTypeFilter = @js($selectedLinkType);
        links.forEach(function(link) {
            if (linkTypeFilter && link.link_type !== linkTypeFilter) return;
            if (!link.source_lat || !link.target_lat) return;
            var typeColors = { fiber: '#22c55e', wireless: '#f59e0b', mpls: '#8b5cf6', vpn: '#ec4899', unknown: '#9ca3af' };
            var color = typeColors[link.link_type] || '#9ca3af';
            var line = L.polyline([[link.source_lat, link.source_lng], [link.target_lat, link.target_lng]], {
                color: color, weight: 3, opacity: 0.8,
                dashArray: link.link_type === 'wireless' ? '5, 10' : null,
            }).addTo(map);
            var label = link.link_type === 'fiber' ? 'فایبر' : link.link_type === 'wireless' ? 'بی‌سیم' : link.link_type;
            line.bindTooltip(
                '<div class="network-map-tooltip">' +
                '<div class="font-bold">' + label + '</div>' +
                '<div>' + link.source_switch + ' → ' + link.target_switch + '</div>' +
                (link.distance_km ? '<div>فاصله: ' + link.distance_km + ' km</div>' : '') +
                (link.vlans.length > 0 ? '<div>VLAN: ' + link.vlans.join(', ') + '</div>' : '') +
                '</div>'
            );
            layers.links.push(line);
        });
    }

    function renderSpof(spofList) {
        clearLayer('spof');
        if (!@js($showSpof)) return;
        spofList.forEach(function(sp) {
            if (!sp.lat || !sp.lng) return;
            var marker = L.circleMarker([sp.lat, sp.lng], {
                radius: 8, fillColor: '#ef4444', color: '#991b1b', weight: 2, fillOpacity: 0.9,
            }).addTo(map);
            marker.bindTooltip(
                '<div class="network-map-tooltip">' +
                '<div class="font-bold text-red-600">⚠ ' + sp.name + '</div>' +
                '<div>واحد: ' + (sp.unit_name || '-') + '</div>' +
                '<div>تعداد واحدها: ' + sp.unit_count + '</div>' +
                '<div>امتیاز ریسک: ' + sp.risk_score + '</div>' +
                '</div>'
            );
            layers.spof.push(marker);
        });
    }

    // Initialize map
    initMap();

    // Listen for data updates from Livewire
    Livewire.on('network-map-data-loaded', function(data) {
        renderSwitches(data.switches);
        renderLinks(data.links);
        renderSpof(data.spof);
    });

    // Initial render
    document.addEventListener('DOMContentLoaded', function() {
        initMap();
    });
</script>
@endscript