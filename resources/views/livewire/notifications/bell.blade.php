<div class="relative" wire:click.away="$set('showDropdown', false)">
    <button wire:click="toggleDropdown" class="btn btn-ghost btn-sm relative">
        <x-icon name="o-bell" class="w-5 h-5" />
        @if($unreadCount > 0)
        <span class="absolute -top-1 -right-1 bg-error text-white text-[10px] font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if($showDropdown)
    <div class="absolute left-0 mt-2 w-80 bg-base-100 rounded-xl shadow-xl border border-base-200 z-50 max-h-96 overflow-hidden" dir="rtl">
        <div class="flex items-center justify-between p-3 border-b border-base-200">
            <h3 class="font-bold text-sm">اعلان‌ها</h3>
            @if($unreadCount > 0)
            <button wire:click="markAllAsRead" class="text-xs text-primary hover:underline">خواندن همه</button>
            @endif
        </div>
        <div class="overflow-y-auto max-h-72">
            @forelse($notifications as $notif)
            <div wire:click="markAsRead('{{ $notif['id'] }}')"
                class="flex items-start gap-3 p-3 hover:bg-base-200 transition cursor-pointer border-b border-base-100 {{ !$notif['is_read'] ? 'bg-primary/5' : '' }}">
                <x-icon name="{{ $notif['icon'] }}" class="w-5 h-5 {{ $notif['color'] }} mt-0.5" />
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold {{ !$notif['is_read'] ? 'text-primary' : '' }}">{{ $notif['title'] }}</p>
                    @if($notif['body'])
                    <p class="text-xs text-base-content/60 line-clamp-2">{{ $notif['body'] }}</p>
                    @endif
                    <p class="text-[10px] text-base-content/40 mt-1">{{ \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($notif['created_at']))->diffForHumans() }}</p>
                </div>
                @if(!$notif['is_read'])
                <span class="w-2 h-2 bg-primary rounded-full mt-2 shrink-0"></span>
                @endif
            </div>
            @empty
            <div class="text-center py-8 text-base-content/30">
                <x-icon name="o-bell-slash" class="w-10 h-10 mx-auto mb-2" />
                <p class="text-sm">اعلانی وجود ندارد</p>
            </div>
            @endforelse
        </div>
        @if(count($notifications) > 0)
        <div class="p-2 border-t border-base-200 text-center">
            <span class="text-xs text-base-content/40">آخرین {{ count($notifications) }} اعلان</span>
        </div>
        @endif
    </div>
    @endif
</div>
