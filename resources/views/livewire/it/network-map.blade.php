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

        $switches = Hardware::with('person.unit')
            ->whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->whereNotNull('switch')->where('switch', '!=', '')->get();

        $this->switches = $switches->groupBy('switch')->map(fn($group) => [
            'name' => $group->first()->switch,
            'lat' => $group->first()->person->unit?->lat,
            'lng' => $group->first()->person->unit?->lng,
            'unit_name' => $group->first()->person->unit?->name,
            'device_count' => $group->count(),
            'vlans' => $group->pluck('vlan')->filter()->unique()->values(),
        ])->values()->toArray();

        $this->links = NetworkLink::with(['sourceUnit', 'targetUnit'])
            ->where(fn($q) => $q->whereIn('source_unit_id', $accessibleIds)->orWhereIn('target_unit_id', $accessibleIds))
            ->get()->map(fn($link) => [
                'id' => $link->id, 'source_switch' => $link->source_switch, 'target_switch' => $link->target_switch,
                'link_type' => $link->link_type, 'vlans' => $link->vlans ?? [], 'distance_km' => $link->distance_km,
                'latency_ms' => $link->latency_ms, 'bandwidth_mbps' => $link->bandwidth_mbps, 'is_redundant' => $link->is_redundant,
                'source_lat' => $link->sourceUnit?->lat, 'source_lng' => $link->sourceUnit?->lng,
                'target_lat' => $link->targetUnit?->lat, 'target_lng' => $link->targetUnit?->lng,
            ])->toArray();

        $vlanData = Hardware::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))
            ->whereNotNull('vlan')->where('vlan', '!=', '')->pluck('vlan')->countBy();
        $vlanColors = ['1'=>'#e74c3c','10'=>'#3498db','20'=>'#2ecc71','30'=>'#f39c12','40'=>'#9b59b6','50'=>'#1abc9c','100'=>'#e67e22','200'=>'#34495e'];
        $this->vlans = $vlanData->map(fn($c,$v) => ['vlan'=>(string)$v,'count'=>$c,'color'=>$vlanColors[$v]??'#'.substr(md5($v),0,6)])->values()->toArray();

        $this->spof = $switches->groupBy('switch')->filter(fn($g) => $g->pluck('person.u_id')->unique()->count() >= 3)
            ->map(fn($group) => [
                'name' => $group->first()->switch, 'lat' => $group->first()->person->unit?->lat,
                'lng' => $group->first()->person->unit?->lng, 'unit_name' => $group->first()->person->unit?->name,
                'unit_count' => $group->pluck('person.u_id')->unique()->count(),
                'risk_score' => $group->pluck('person.u_id')->unique()->count() * 10 + $group->count(),
            ])->sortByDesc('risk_score')->values()->toArray();

        $this->loading = false;
        $this->loadStats();
        $this->dispatch('network-map-data-loaded', switches: $this->switches, links: $this->links, vlans: $this->vlans, spof: $this->spof);
    }

    public function loadStats(): void
    {
        $this->stats = [
            'total_switches' => count($this->switches),
            'total_links' => count($this->links),
            'total_vlans' => count($this->vlans),
            'total_devices' => array_sum(array_column($this->switches, 'device_count')),
            'spof_count' => count($this->spof),
        ];
    }

    public function getLinkTypeLabel(string $type): string
    {
        return match($type) { 'fiber' => 'فایبر نوری', 'wireless' => 'بی‌سیم', 'mpls' => 'MPLS', 'vpn' => 'VPN', default => 'نامشخص' };
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

    <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4">
        <div class="stat bg-base-200 rounded-box p-3 shadow"><div class="stat-title text-xs">سوئیچ‌ها</div><div class="stat-value text-lg">{{ $stats['total_switches'] ?? 0 }}</div></div>
        <div class="stat bg-base-200 rounded-box p-3 shadow"><div class="stat-title text-xs">لینک‌ها</div><div class="stat-value text-lg">{{ $stats['total_links'] ?? 0 }}</div></div>
        <div class="stat bg-base-200 rounded-box p-3 shadow"><div class="stat-title text-xs">VLANها</div><div class="stat-value text-lg">{{ $stats['total_vlans'] ?? 0 }}</div></div>
        <div class="stat bg-base-200 rounded-box p-3 shadow"><div class="stat-title text-xs">تجهیزات</div><div class="stat-value text-lg">{{ $stats['total_devices'] ?? 0 }}</div></div>
        <div class="stat bg-base-200 rounded-box p-3 shadow"><div class="stat-title text-xs">نقاط شکست</div><div class="stat-value text-lg text-error">{{ $stats['spof_count'] ?? 0 }}</div></div>
    </div>

    <x-card shadow class="mb-4 p-3">
        <div class="flex flex-wrap items-center gap-4">
            <x-toggle right wire:model.live="showSwitches" label="سوئیچ‌ها" />
            <x-toggle right wire:model.live="showLinks" label="لینک‌ها" />
            <x-toggle right wire:model.live="showVlans" label="VLANها" />
            <x-toggle right wire:model.live="showDevices" label="تجهیزات" />
            <x-toggle right wire:model.live="showSpof" label="نقاط شکست" />
            <div class="divider divider-horizontal"></div>
            <select class="select select-bordered select-sm w-32 text-xs" wire:model.live="selectedLinkType">
                <option value="">همه</option>
                <option value="fiber">فایبر</option>
                <option value="wireless">بی‌سیم</option>
                <option value="mpls">MPLS</option>
                <option value="vpn">VPN</option>
            </select>
            <x-button wire:click="loadData" label="بروزرسانی" icon="o-arrow-path" class="btn-sm btn-primary" />
        </div>
    </x-card>

    <x-card shadow class="p-0">
        <div class="relative" style="min-height: 600px;" wire:ignore.self>
            <div id="network-map" style="width: 100%; height: 600px;" wire:ignore></div>
            <div class="absolute top-4 right-4 z-10 w-64 space-y-2" x-data="{ open: true }">
                <button class="btn btn-sm btn-circle btn-ghost bg-base-100/80 shadow" @click="open = !open"><x-heroicon-o-bars-3 /></button>
                <div x-show="open" x-transition class="bg-base-100/90 backdrop-blur rounded-box shadow p-3 space-y-3 max-h-[500px] overflow-y-auto">
                    @if ($showVlans && count($vlans) > 0)
                    <div><h4 class="font-bold text-sm">VLANها</h4>
                        @foreach ($vlans as $v)
                        <div class="flex items-center gap-2 text-xs"><span class="w-3 h-3 rounded-full" style="background: {{ $v['color'] }}"></span><span>VLAN {{ $v['vlan'] }} ({{ $v['count'] }} دستگاه)</span></div>
                        @endforeach
                    </div>
                    @endif
                    @if ($showSpof && count($spof) > 0)
                    <div><h4 class="font-bold text-sm text-error">نقاط شکست</h4>
                        @foreach ($spof as $s)
                        <div class="text-xs bg-error/10 rounded p-1 mb-1"><span class="font-bold">{{ $s['name'] }}</span> <span class="text-error">({{ $s['unit_count'] }} واحد)</span></div>
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
    .network-map-tooltip { background: var(--b1); color: var(--bc); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--b3); font-size: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
    .network-map-tooltip .font-bold { font-weight: 700; }
</style>
@endassets

@script
<script>
    var map;
    var allData = { switches: [], links: [], vlans: [], spof: [] };

    function initMap() {
        if (map) return;
        map = L.map('network-map', { zoomControl: true }).setView([35.5, 48.0], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 18 }).addTo(map);
    }

    function clearAll() {
        Object.keys(allData).forEach(function(k) {
            if (k === 'vlans') return;
            if (allData[k].__layer) allData[k].forEach(function(l) { if (map) map.removeLayer(l); });
            allData[k] = [];
        });
    }

    function renderSwitches(items) {
        var parent = document.querySelector('[wire\\:model\\.live="showSwitches"]');
        var visible = parent ? parent.querySelector('input')?.checked : true;
        if (allData.switches.__layer) allData.switches.__layer.forEach(function(l) { if (map) map.removeLayer(l); });
        allData.switches = [];
        allData.switches.__layer = [];
        if (!visible || !items || items.length === 0) return;
        items.forEach(function(sw) {
            if (!sw.lat || !sw.lng) return;
            var m = L.circleMarker([sw.lat, sw.lng], { radius: 6, fillColor: '#3b82f6', color: '#1e40af', weight: 2, fillOpacity: 0.8 }).addTo(map);
            m.bindTooltip('<div class="network-map-tooltip"><div class="font-bold">' + sw.name + '</div><div>واحد: ' + (sw.unit_name || '-') + '</div><div>دستگاه‌ها: ' + sw.device_count + '</div><div>VLAN: ' + (sw.vlans?.length > 0 ? sw.vlans.join(', ') : '-') + '</div></div>');
            allData.switches.push(sw);
            allData.switches.__layer.push(m);
        });
    }

    function renderLinks(items) {
        var parent = document.querySelector('[wire\\:model\\.live="showLinks"]');
        var visible = parent ? parent.querySelector('input')?.checked : true;
        if (allData.links.__layer) allData.links.__layer.forEach(function(l) { if (map) map.removeLayer(l); });
        allData.links = [];
        allData.links.__layer = [];
        if (!visible || !items || items.length === 0) return;
        var typeColors = { fiber: '#22c55e', wireless: '#f59e0b', mpls: '#8b5cf6', vpn: '#ec4899', unknown: '#9ca3af' };
        items.forEach(function(link) {
            if (!link.source_lat || !link.target_lat) return;
            var color = typeColors[link.link_type] || '#9ca3af';
            var line = L.polyline([[link.source_lat, link.source_lng], [link.target_lat, link.target_lng]], { color: color, weight: 3, opacity: 0.8, dashArray: link.link_type === 'wireless' ? '5, 10' : null }).addTo(map);
            var label = { fiber: 'فایبر', wireless: 'بی‌سیم', mpls: 'MPLS', vpn: 'VPN' }[link.link_type] || link.link_type;
            line.bindTooltip('<div class="network-map-tooltip"><div class="font-bold">' + label + '</div><div>' + link.source_switch + ' → ' + link.target_switch + '</div>' + (link.distance_km ? '<div>فاصله: ' + link.distance_km + ' km</div>' : '') + (link.vlans?.length > 0 ? '<div>VLAN: ' + link.vlans.join(', ') + '</div>' : '') + '</div>');
            allData.links.push(link);
            allData.links.__layer.push(line);
        });
    }

    function renderSpof(items) {
        var parent = document.querySelector('[wire\\:model\\.live="showSpof"]');
        var visible = parent ? parent.querySelector('input')?.checked : true;
        if (allData.spof.__layer) allData.spof.__layer.forEach(function(l) { if (map) map.removeLayer(l); });
        allData.spof = [];
        allData.spof.__layer = [];
        if (!visible || !items || items.length === 0) return;
        items.forEach(function(sp) {
            if (!sp.lat || !sp.lng) return;
            var m = L.circleMarker([sp.lat, sp.lng], { radius: 8, fillColor: '#ef4444', color: '#991b1b', weight: 2, fillOpacity: 0.9 }).addTo(map);
            m.bindTooltip('<div class="network-map-tooltip"><div class="font-bold text-red-600">⚠ ' + sp.name + '</div><div>واحد: ' + (sp.unit_name || '-') + '</div><div>تعداد واحدها: ' + sp.unit_count + '</div><div>امتیاز ریسک: ' + sp.risk_score + '</div></div>');
            allData.spof.push(sp);
            allData.spof.__layer.push(m);
        });
    }

    initMap();

    Livewire.on('network-map-data-loaded', function(switches, links, vlans, spof) {
        if (switches) allData.switches = switches;
        if (links) allData.links = links;
        if (vlans) allData.vlans = vlans;
        if (spof) allData.spof = spof;
        renderSwitches(allData.switches);
        renderLinks(allData.links);
        renderSpof(allData.spof);
    });

    document.addEventListener('DOMContentLoaded', function() { initMap(); });
</script>
@endscript