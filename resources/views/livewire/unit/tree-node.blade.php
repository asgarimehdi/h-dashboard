{{--
    One node of the shared `unit.tree` component. Recurses into itself for
    children, so the tree markup lives in exactly one place.

    Props:
      unit        — the Unit model for this node
      level       — depth, 0 for a root (drives the guide-line indent)
      isLast      — last sibling (shortens the vertical guide line)
      badgeView   — optional Blade view rendered per node, receives $unit
      personCounts— unit-id => count map, forwarded to the badge view

    All mechanics (expanded set, lazy children, search) live on the parent
    `unit.tree` component — this template only renders.
--}}
{{-- Props documented in livewire/unit/tree.blade.php. --}}
@props(['unit', 'level' => 0, 'isLast' => false, 'badgeView' => null, 'personCounts' => []])

@php
    use App\Services\AccessService;
    use App\Services\UnitTreeService;

    // Children come from the tree's lazy cache. When a node has never been
    // loaded we still need to know whether to draw a toggle, so fall back to
    // the service — scoped to what the current user may see, so a node never
    // advertises children the user is not allowed to open.
    $childUnits = $this->lazyChildren[$unit->id] ?? null;

    if ($childUnits !== null) {
        $hasChildren = $childUnits->isNotEmpty();
    } else {
        $hasChildren = app(UnitTreeService::class)
            ->childrenOf($unit->id, app(AccessService::class)->accessibleUnitIds())
            ->isNotEmpty();
    }

    $isExpanded = in_array((string) $unit->id, $this->expanded);
    $isMatch = ! empty($this->search) && mb_strpos($unit->name, $this->search) !== false;
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
                    @include($badgeView, ['unit' => $unit, 'personCounts' => $personCounts])
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
                    'personCounts' => $personCounts,
                ])
            @endforeach
        </div>
    @endif
</div>
