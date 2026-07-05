@php
    $hasChildren = !empty($unit['children_recursive']) || !empty($unit['children']);
    $isExpanded = in_array($unit['id'], $expandedUnitIds);
@endphp

<div class="border border-base-200 rounded-lg mb-1" style="margin-right: {{ $level * 20 }}px">
    <div class="flex items-center gap-2 p-2 hover:bg-base-200/50 cursor-pointer transition"
         wire:click="selectUnit({{ $unit['id'] }})">
        @if($hasChildren)
        <button wire:click.stop="toggleExpand({{ $unit['id'] }})" class="btn btn-ghost btn-xs">
            <x-icon name="{{ $isExpanded ? 'o-chevron-down' : 'o-chevron-right' }}" class="w-4 h-4" />
        </button>
        @else
        <span class="w-6"></span>
        @endif

        <x-icon name="o-building-office" class="w-4 h-4 text-primary" />

        <span class="font-bold text-sm">{{ $unit['name'] }}</span>

        @if(isset($unit['unit_type']))
        <x-badge value="{{ $unit['unit_type']['name'] }}" class="badge-ghost badge-sm" />
        @endif

        @if(isset($unit['assigned_users']) && count($unit['assigned_users']) > 0)
        <span class="text-xs opacity-50">({{ count($unit['assigned_users']) }} نفر)</span>
        @endif
    </div>

    @if($isExpanded && $hasChildren)
    <div class="pr-4 pb-1">
        @php
            $children = $unit['children_recursive'] ?? $unit['children'] ?? [];
        @endphp
        @foreach($children as $child)
            @include('livewire.units._tree-node', ['unit' => $child, 'level' => $level + 1])
        @endforeach
    </div>
    @endif
</div>
