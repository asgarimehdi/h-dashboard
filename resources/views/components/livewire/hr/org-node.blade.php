<div class="border border-base-300 rounded-lg mb-2 overflow-hidden">
    <div class="flex items-center gap-2 p-3 bg-base-200/50 hover:bg-base-200 transition-colors cursor-pointer"
         wire:click="toggle('{{ $node['id'] }}')">
        @if (! empty($node['children']))
            <x-icon name="{{ in_array($node['id'], $expanded) ? 'o-chevron-down' : 'o-chevron-left' }}" class="w-4 h-4" />
        @else
            <span class="w-4"></span>
        @endif
        <x-icon name="o-building-office" class="w-4 h-4 text-primary" />
        <span class="font-bold text-sm">{{ $node['name'] }}</span>
        <span class="badge badge-sm badge-ghost">{{ $node['personnel_count'] }} نفر</span>
        @if ($node['personnel_count'] === 0)
            <span class="badge badge-sm badge-error">خالی</span>
        @endif
    </div>

    @if (! empty($node['children']) && in_array($node['id'], $expanded))
        <div class="pr-6 py-1">
            @foreach ($node['children'] as $child)
                <x-livewire.hr.org-node :node="$child" :expanded="$expanded" :search="$search" />
            @endforeach
        </div>
    @endif
</div>
