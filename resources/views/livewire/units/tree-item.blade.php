@props(['unit', 'level' => 0, 'isLast' => false])

@php
    $hasChildren = $unit->childrenRecursive->count() > 0;
    $isExpanded = in_array((string)$unit->id, $this->expanded);
    $isMatch = !empty($this->search) && mb_strpos($unit->name, $this->search) !== false;
@endphp

<div class="relative">
    <div class="flex items-center group">
        
        {{-- خطوط راهنما --}}
        @if($level > 0)
            <div class="relative" style="width: {{ $level * 28 }}px;">
                <div class="tree-line-leaf"></div>
                @if(!$isLast)
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
            "flex items-center gap-3 p-3 my-2 rounded-xl transition-all border-2 flex-1 shadow-sm",
            "border-primary bg-primary/10 scale-[1.02]" => $isMatch,
            "border-base-300 bg-base-100 hover:border-gray-400" => !$isMatch
        ])>
            
            {{-- آیکون وضعیت --}}
            <div wire:click="toggle({{ $unit->id }})" class="cursor-pointer">
                @if($hasChildren)
                    <div @class([
                        "w-7 h-7 flex items-center justify-center rounded-lg transition-colors",
                        "bg-primary text-white" => $isExpanded,
                        "bg-base-300 text-base-content" => !$isExpanded
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
                @if($unit->unitType)
                    <span class="text-[11px] opacity-70 font-medium italic">{{ $unit->unitType->name }}</span>
                @endif
            </div>

            {{-- دکمه‌ها --}}
            <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                <x-button icon="o-plus" class="btn-xs btn-outline btn-success" tooltip="افزودن فرزند" />
                <x-button icon="o-pencil" class="btn-xs btn-outline btn-info" tooltip="ویرایش" />
            </div>
        </div>
    </div>

    {{-- فرزندان --}}
    @if($hasChildren && $isExpanded)
        {{-- ایجاد فاصله و خط عمودی ممتد برای زیرمجموعه‌ها --}}
        <div class="mr-9">
            @foreach($unit->childrenRecursive as $child)
                @include('livewire.units.tree-item', [
                    'unit' => $child, 
                    'level' => $level + 1, 
                    'isLast' => $loop->last
                ])
            @endforeach
        </div>
    @endif
</div>