{{--
    One node of the shared `unit.tree` component. Recurses into itself for
    children, so the tree markup lives in exactly one place. Props contract
    documented in livewire/unit/tree.blade.php — this template only renders;
    all mechanics (expanded set, lazy children, search, match highlight) live
    on the parent `unit.tree` component.
--}}
@props(['unit', 'level' => 0, 'isLast' => false, 'badgeView' => null, 'badgeData' => []])

@php
    use App\Services\UnitTreeService;

    // Children come from the tree's lazy cache. When a node has never been
    // loaded we still need to know whether to draw a toggle, so ask the
    // service for an existence check — scoped to what the current user may
    // see (a node never advertises children the user cannot open) and cheap
    // enough to run per unloaded node (no rows are fetched).
    $childUnits = $this->lazyChildren[$unit->id] ?? null;

    if ($childUnits !== null) {
        $hasChildren = $childUnits->isNotEmpty();
    } else {
        $hasChildren = app(UnitTreeService::class)
            ->hasChildren((int) $unit->id, $this->accessibleIds());
    }

    $isExpanded = in_array((string) $unit->id, $this->expanded, true);

    // Highlight from the search result set, never by re-deriving the match
    // against the raw term — folded matching and the length floor live in
    // UnitTreeService, and a second implementation here would drift.
    $isMatch = isset($this->matchIds[(int) $unit->id]);
@endphp

<div class="relative">
    <div class="flex items-center group">

        {{-- خطوط راهنما --}}
        @if ($level > 0)
            <div class="relative" style="width: {{ $level * 28 }}px;">
                <div class="tree-line-leaf"></div>
                @if (! $isLast)
                    <div class="tree-line-branch"></div>
                @else
                    {{-- برای آخرین فرزند، خط عمودی را تا نیمه قطع می‌کنیم --}}
                    <div class="tree-line-branch" style="bottom: auto; height: 25px;"></div>
                @endif
                <div class="tree-node-dot"></div>
            </div>
        @endif

        {{-- باکس واحد --}}
        <div @class([
            "flex items-center gap-3 p-3 my-2 rounded-xl transition-all border-2 flex-1 shadow-sm cursor-pointer",
            "border-primary bg-primary/10 scale-[1.02]" => $isMatch,
            "border-base-300 bg-base-100 hover:border-gray-400" => ! $isMatch,
        ]) wire:click="selectNode({{ $unit->id }})">

            {{-- آیکون وضعیت --}}
            <div wire:click.stop="toggle({{ $unit->id }})" class="cursor-pointer">
                @if ($hasChildren)
                    <div @class([
                        "w-7 h-7 flex items-center justify-center rounded-lg transition-colors",
                        "bg-primary text-white" => $isExpanded,
                        "bg-base-300 text-base-content" => ! $isExpanded,
                    ])>
                        <x-icon name="{{ $isExpanded ? 'o-minus' : 'o-plus' }}" class="w-4 h-4" />
                    </div>
                @else
                    <div class="w-7 h-7 flex items-center justify-center">
                        <div class="w-2 h-2 rounded-full bg-gray-400"></div>
                    </div>
                @endif
            </div>

            {{-- متن --}}
            <div class="flex flex-col flex-1">
                <span @class(["font-extrabold text-sm", "text-primary" => $isMatch])>
                    {{ $unit->name }}
                </span>
                @if ($unit->unitType)
                    <span class="text-[11px] opacity-70 font-medium italic">{{ $unit->unitType->name }}</span>
                @endif

                {{-- Badge slot: the one plug-in point. Omitted entirely when the
                     consuming page supplies no badgeView. --}}
                @if ($badgeView)
                    @include($badgeView, ['unit' => $unit, 'badgeData' => $badgeData])
                @endif
            </div>

        </div>
    </div>

    {{-- فرزندان --}}
    @if ($hasChildren && $isExpanded)
        {{-- ایجاد فاصله و خط عمودی ممتد برای زیرمجموعه‌ها --}}
        <div class="mr-9">
            @foreach ($childUnits as $child)
                @include('livewire.unit.tree-node', [
                    'unit' => $child,
                    'level' => $level + 1,
                    'isLast' => $loop->last,
                    'badgeView' => $badgeView,
                    'badgeData' => $badgeData,
                ])
            @endforeach
        </div>
    @endif
</div>
