<div>
    <x-header title="داشبورد منابع انسانی" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="hr-dashboard" wireModel="showHelpModal" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    {{-- Overview stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-stat title="کل پرسنل" value="{{ $stats['total'] }}" icon="o-users" color="text-primary" />
        <x-stat title="فعال" value="{{ $stats['active'] }}" icon="o-user-circle" color="text-success" />
        <x-stat title="بازنشسته" value="{{ $stats['retired'] }}" icon="o-user-minus" color="text-warning" />
        <x-stat title="واحدهای بدون پرسنل" value="{{ count($vacancies) }}" icon="o-exclamation-triangle" color="text-error" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- By Unit --}}
        <x-card shadow title="پرسنل به تفکیک واحد">
            <div class="space-y-2">
                @forelse ($byUnit as $row)
                    <div class="flex items-center gap-2">
                        <div class="flex-1 text-sm truncate">{{ $row['name'] }}</div>
                        <div class="w-1/2 bg-base-200 rounded-full h-2 overflow-hidden">
                            <div class="bg-primary h-2 rounded-full"
                                 style="width: {{ $stats['total'] ? round($row['total'] / $stats['total'] * 100) : 0 }}%"></div>
                        </div>
                        <div class="text-sm font-bold w-8 text-left">{{ $row['total'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/50">داده‌ای موجود نیست</p>
                @endforelse
            </div>
        </x-card>

        {{-- By Semat --}}
        <x-card shadow title="پرسنل به تفکیک سمت">
            <div class="space-y-2">
                @forelse ($bySemat as $row)
                    <div class="flex items-center gap-2">
                        <div class="flex-1 text-sm truncate">{{ $row['name'] }}</div>
                        <div class="w-1/2 bg-base-200 rounded-full h-2 overflow-hidden">
                            <div class="bg-success h-2 rounded-full"
                                 style="width: {{ $stats['total'] ? round($row['total'] / $stats['total'] * 100) : 0 }}%"></div>
                        </div>
                        <div class="text-sm font-bold w-8 text-left">{{ $row['total'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/50">داده‌ای موجود نیست</p>
                @endforelse
            </div>
        </x-card>

        {{-- By Tahsil --}}
        <x-card shadow title="تحصیلات (Tahsil)">
            <div class="space-y-2">
                @forelse ($byTahsil as $row)
                    <div class="flex items-center gap-2">
                        <div class="flex-1 text-sm truncate">{{ $row['name'] }}</div>
                        <div class="w-1/2 bg-base-200 rounded-full h-2 overflow-hidden">
                            <div class="bg-info h-2 rounded-full"
                                 style="width: {{ $stats['total'] ? round($row['total'] / $stats['total'] * 100) : 0 }}%"></div>
                        </div>
                        <div class="text-sm font-bold w-8 text-left">{{ $row['total'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/50">داده‌ای موجود نیست</p>
                @endforelse
            </div>
        </x-card>

        {{-- By Estekhdam --}}
        <x-card shadow title="نوع استخدام (Estekhdam)">
            <div class="space-y-2">
                @forelse ($byEstekhdam as $row)
                    <div class="flex items-center gap-2">
                        <div class="flex-1 text-sm truncate">{{ $row['name'] }}</div>
                        <div class="w-1/2 bg-base-200 rounded-full h-2 overflow-hidden">
                            <div class="bg-warning h-2 rounded-full"
                                 style="width: {{ $stats['total'] ? round($row['total'] / $stats['total'] * 100) : 0 }}%"></div>
                        </div>
                        <div class="text-sm font-bold w-8 text-left">{{ $row['total'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/50">داده‌ای موجود نیست</p>
                @endforelse
            </div>
        </x-card>
    </div>

    {{-- Vacancies --}}
    <x-card shadow title="واحدهای بدون پرسنل (Vacancies)" class="mt-4">
        @forelse ($vacancies as $v)
            <span class="badge badge-outline badge-error ml-1 mb-1">{{ $v['name'] }}</span>
        @empty
            <p class="text-sm text-base-content/50">همه واحدها پرسنل دارند 🎉</p>
        @endforelse
    </x-card>
</div>
