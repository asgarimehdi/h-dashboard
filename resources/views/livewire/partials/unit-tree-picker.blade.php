@php
    // Group units by parent_id for tree traversal
    $byParent = collect($units)->groupBy('parent_id');
    $roots = $byParent->get(null, collect());
    if ($roots->isEmpty()) {
        $roots = $byParent->get(0, collect());
    }

    $renderTree = function ($items) use (&$renderTree, $byParent) {
        $out = '';
        foreach ($items->sortBy('name')->values() as $u) {
            $children = $byParent->get($u['id'], collect());
            $type = $u['unit_type_name'] ?? '';
            $uid = $u['id'];
            $out .= '<div class="select-none">';
            $out .= '<div @click="toggle('.$uid.')" class="flex items-center gap-2 px-2 py-1.5 rounded cursor-pointer hover:bg-primary/10 transition-colors">';
            if ($children->isNotEmpty()) {
                $out .= '<button type="button" @click.stop="toggleExpanded('.$uid.')" class="w-5 h-5 flex items-center justify-center text-base-content/40 hover:text-base-content text-xs font-bold">';
                $out .= '<span x-text="expanded['.$uid.'] ? \'−\' : \'+\'"></span>';
                $out .= '</button>';
            } else {
                $out .= '<span class="w-5"></span>';
            }
            $nameEsc = htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8');
            $out .= '<span class="text-sm flex-1 truncate" :class="isSelected('.$uid.') ? \'font-bold text-primary\' : \'\'">'.$nameEsc.'</span>';
            if ($type) {
                $typeEsc = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
                $out .= '<span class="text-[10px] opacity-50">'.$typeEsc.'</span>';
            }
            $out .= '</div>';
            if ($children->isNotEmpty()) {
                $out .= '<div x-show="expanded['.$uid.']" class="mr-4 border-r border-base-300 ps-2">';
                $out .= $renderTree($children);
                $out .= '</div>';
            }
            $out .= '</div>';
        }

        return $out;
    };

    $modelAttr = $model ?? null;
    if (! $modelAttr && isset($attributes) && $attributes->wire('model')) {
        $modelAttr = $attributes->wire('model')->value();
    }
    $modelEsc = htmlspecialchars((string) $modelAttr, ENT_QUOTES, 'UTF-8');
    $alwaysOpen = $alwaysOpen ?? false;
    $label = $label ?? null;
@endphp

<div
    x-data="{
        search: '',
        open: {{ $alwaysOpen ? 'true' : 'false' }},
        expanded: {},
        flat: {{ \Illuminate\Support\Js::from(collect($units)->map(fn ($u) => ['id' => $u['id'], 'name' => $u['name']])->values()->all()) }},
        multiple: {{ ($multiple ?? false) ? 'true' : 'false' }},
        alwaysOpen: {{ $alwaysOpen ? 'true' : 'false' }},
        selected: $wire.{{ $modelEsc }},
        isSelected(id) {
            if (this.multiple) return Array.isArray(this.selected) && this.selected.map(Number).includes(Number(id));
            return String(this.selected) === String(id);
        },
        toggle(id) {
            if (this.multiple) {
                const arr = Array.isArray(this.selected) ? this.selected : [];
                const idx = arr.findIndex((x) => String(x) === String(id));
                if (idx === -1) this.$wire.{{ $modelEsc }} = [...arr, Number(id)];
                else this.$wire.{{ $modelEsc }} = arr.filter((x) => String(x) !== String(id));
            } else {
                this.$wire.{{ $modelEsc }} = Number(id);
                if (!this.alwaysOpen) this.open = false;
            }
        },
        toggleExpanded(id) {
            this.expanded[id] = !this.expanded[id];
        },
        remove(id) {
            if (this.multiple) {
                this.$wire.{{ $modelEsc }} = (this.selected ?? []).filter((x) => String(x) !== String(id));
            } else {
                this.$wire.{{ $modelEsc }} = null;
                if (!this.alwaysOpen) this.open = false;
            }
        },
        nameOf(id) { return this.flat.find((u) => String(u.id) === String(id))?.name ?? '-'; },
        expandForSearch() {
            const s = this.search.trim().toLowerCase();
            if (!s) return;
            this.expanded = {};
            this.flat.forEach((u) => {
                if (u.name.toLowerCase().includes(s)) {
                    this.expanded[u.id] = true;
                }
            });
        }
    }"
    x-init="$watch('search', () => expandForSearch()); $watch('$wire.{{ $modelEsc }}', value => selected = value)"
    @if(! $alwaysOpen)
        @click.outside="open = false"
    @endif
    class="relative"
>
    @if(! empty($label))
        <label class="text-sm font-medium block mb-1">{{ $label }}</label>
    @endif

    @if(! $alwaysOpen)
        @if($multiple ?? false)
            <div class="flex flex-wrap gap-1 p-2 border border-base-300 rounded-lg min-h-[42px] bg-base-100 cursor-pointer hover:border-primary focus-within:ring-2 focus-within:ring-primary" @click="open = !open">
                <template x-if="!selected || selected.length === 0">
                    <span class="text-base-content/40 text-sm">انتخاب واحدها...</span>
                </template>
                <template x-for="id in (selected ?? [])" :key="id">
                    <span class="badge badge-primary gap-1">
                        <span x-text="nameOf(id)"></span>
                        <button type="button" @click.stop="remove(id)" class="text-white hover:text-error">×</button>
                    </span>
                </template>
            </div>
        @else
            <div class="flex items-center p-2 border border-base-300 rounded-lg min-h-[42px] bg-base-100 cursor-pointer hover:border-primary focus-within:ring-2 focus-within:ring-primary" @click="open = !open">
                <span x-show="!selected" class="text-base-content/40 text-sm">انتخاب واحد...</span>
                <span x-show="selected" x-text="nameOf(selected)" class="text-sm"></span>
            </div>
        @endif
    @else
        @if($multiple ?? false)
            <div class="flex flex-wrap gap-1 p-2 border border-base-300 rounded-lg min-h-[42px] bg-base-100 mb-2">
                <template x-if="!selected || selected.length === 0">
                    <span class="text-base-content/40 text-sm">هنوز واحدی انتخاب نشده</span>
                </template>
                <template x-for="id in (selected ?? [])" :key="id">
                    <span class="badge badge-primary gap-1">
                        <span x-text="nameOf(id)"></span>
                        <button type="button" @click.stop="remove(id)" class="text-white hover:text-error">×</button>
                    </span>
                </template>
            </div>
        @else
            <div class="flex items-center p-2 border border-base-300 rounded-lg min-h-[42px] bg-base-100 mb-2">
                <span x-show="!selected" class="text-base-content/40 text-sm">هنوز واحدی انتخاب نشده</span>
                <span x-show="selected" x-text="nameOf(selected)" class="text-sm font-medium text-primary"></span>
            </div>
        @endif
    @endif

    <div
        x-show="open"
        x-transition
        @click.stop
        @class([
            'bg-base-100 border border-base-300 rounded-lg shadow-2xl overflow-auto',
            'absolute z-50 w-full mt-1 max-h-80' => ! $alwaysOpen,
            'relative w-full max-h-96' => $alwaysOpen,
        ])
    >
        <div class="p-2 sticky top-0 bg-base-100 border-b border-base-300 z-10">
            <input
                type="text"
                placeholder="جستجوی واحد..."
                x-model="search"
                class="input input-bordered input-sm w-full"
                @click.stop
            />
        </div>

        <div class="p-2 space-y-0.5">
            {!! $renderTree($roots) !!}
            @if($roots->isEmpty())
                <p class="text-center text-sm text-base-content/40 py-4">واحدی یافت نشد</p>
            @endif
        </div>
    </div>
</div>
