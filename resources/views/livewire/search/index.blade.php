<?php

use Livewire\Component;
use App\Models\{Ticket, Todo, User, Unit};
use Livewire\Attributes\Layout;

return new class extends Component
{
    public string $query = '';
    public array $results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => []];
    public bool $hasSearched = false;

    public function updatedQuery(): void
    {
        if (strlen($this->query) < 2) {
            $this->results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => []];
            $this->hasSearched = false;
            return;
        }
        $this->search();
    }

    public function search(): void
    {
        if (strlen($this->query) < 2) return;

        $q = $this->query;

        $this->results = [
            'tickets' => Ticket::accessible()
                ->where(function ($query) use ($q) {
                    $query->where('subject', 'like', "%{$q}%")
                          ->orWhere('ticket_code', 'like', "%{$q}%");
                })
                ->with(['user.person', 'unit'])
                ->latest()
                ->take(10)
                ->get()
                ->toArray(),

            'todos' => Todo::accessible()
                ->where('title', 'like', "%{$q}%")
                ->latest()
                ->take(10)
                ->get()
                ->toArray(),

            'users' => User::with('person')
                ->whereHas('person', function ($query) use ($q) {
                    $query->where('f_name', 'like', "%{$q}%")
                          ->orWhere('l_name', 'like', "%{$q}%");
                })
                ->take(10)
                ->get()
                ->toArray(),

            'units' => Unit::where('name', 'like', "%{$q}%")
                ->take(10)
                ->get()
                ->toArray(),
        ];

        $this->hasSearched = true;
    }

    public function getTotalCount(): int
    {
        return count($this->results['tickets'])
            + count($this->results['todos'])
            + count($this->results['users'])
            + count($this->results['units']);
    }

    public function getTicketStatusColor(string $status): string
    {
        return match($status) {
            'created' => 'badge-neutral',
            'forwarded' => 'badge-warning',
            'accepted' => 'badge-info',
            'completed' => 'badge-success',
            'rejected' => 'badge-error',
            default => 'badge-ghost',
        };
    }

}; ?>

<div dir="rtl">
    <x-header title="جستجوی کلی" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="mb-6">
            <x-input
                wire:model.live.debounce.300ms="query"
                label="عبارت جستجو"
                placeholder="حداقل ۲ کاراکتر وارد کنید..."
                icon="o-magnifying-glass"
                clearable
            />
        </div>

        @if($hasSearched)
            @if($totalCount = $this->getTotalCount())
                <div class="text-sm text-base-content/60 mb-4">
                    {{ $totalCount }} نتیجه یافت شد
                </div>

                {{-- تیکت‌ها --}}
                @if(count($results['tickets']))
                    <div class="mb-6">
                        <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
                            <x-icon name="o-ticket" class="w-5 h-5" />
                            تیکت‌ها
                            <span class="badge badge-sm">{{ count($results['tickets']) }}</span>
                        </h3>
                        <div class="space-y-2">
                            @foreach($results['tickets'] as $ticket)
                                <a href="/tickets/inbox?highlight={{ $ticket['id'] }}"
                                   wire:navigate
                                   class="flex items-center justify-between p-3 rounded-lg bg-base-200 hover:bg-base-300 transition">
                                    <div class="flex items-center gap-3">
                                        <x-icon name="o-ticket" class="w-5 h-5 text-primary" />
                                        <div>
                                            <div class="font-medium">{{ $ticket['subject'] }}</div>
                                            <div class="text-xs text-base-content/50">
                                                کد: {{ $ticket['ticket_code'] }}
                                                | واحد: {{ $ticket['unit']['name'] ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                    <span class="badge {{ $this->getTicketStatusColor($ticket['status']) }} badge-sm">
                                        {{ $ticket['status'] }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- کاربران --}}
                @if(count($results['users']))
                    <div class="mb-6">
                        <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
                            <x-icon name="o-user" class="w-5 h-5" />
                            کاربران
                            <span class="badge badge-sm">{{ count($results['users']) }}</span>
                        </h3>
                        <div class="space-y-2">
                            @foreach($results['users'] as $user)
                                <a href="/profile?id={{ $user['id'] }}"
                                   wire:navigate
                                   class="flex items-center gap-3 p-3 rounded-lg bg-base-200 hover:bg-base-300 transition">
                                    <x-icon name="o-user-circle" class="w-8 h-8 text-primary" />
                                    <div>
                                        <div class="font-medium">
                                            {{ $user['person']['f_name'] ?? '' }} {{ $user['person']['l_name'] ?? '' }}
                                        </div>
                                        @if(isset($user['person']['semat']['name']))
                                            <div class="text-xs text-base-content/50">
                                                {{ $user['person']['semat']['name'] }}
                                            </div>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- واحدها --}}
                @if(count($results['units']))
                    <div class="mb-6">
                        <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
                            <x-icon name="o-building-library" class="w-5 h-5" />
                            واحدها
                            <span class="badge badge-sm">{{ count($results['units']) }}</span>
                        </h3>
                        <div class="space-y-2">
                            @foreach($results['units'] as $unit)
                                <a href="/units"
                                   wire:navigate
                                   class="flex items-center gap-3 p-3 rounded-lg bg-base-200 hover:bg-base-300 transition">
                                    <x-icon name="o-building-office-2" class="w-8 h-8 text-primary" />
                                    <div class="font-medium">{{ $unit['name'] }}</div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- کارهای روزانه --}}
                @if(count($results['todos']))
                    <div class="mb-6">
                        <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
                            <x-icon name="o-check-circle" class="w-5 h-5" />
                            کارهای روزانه
                            <span class="badge badge-sm">{{ count($results['todos']) }}</span>
                        </h3>
                        <div class="space-y-2">
                            @foreach($results['todos'] as $todo)
                                <a href="/todo"
                                   wire:navigate
                                   class="flex items-center gap-3 p-3 rounded-lg bg-base-200 hover:bg-base-300 transition">
                                    <x-icon name="o-calendar" class="w-5 h-5 text-primary" />
                                    <div class="flex-1">
                                        <div class="font-medium">{{ $todo['title'] }}</div>
                                        <div class="text-xs text-base-content/50">
                                            شروع: {{ jdate($todo['start_at'])->format('Y/m/d') }}
                                        </div>
                                    </div>
                                    @if($todo['is_completed'])
                                        <x-icon name="o-check-badge" class="w-5 h-5 text-success" />
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                <div class="text-center py-12">
                    <x-icon name="o-magnifying-glass" class="w-16 h-16 mx-auto text-base-content/20 mb-4" />
                    <p class="text-lg text-base-content/50">نتیجه‌ای یافت نشد</p>
                    <p class="text-sm text-base-content/30 mt-2">عبارت دیگری جستجو کنید</p>
                </div>
            @endif
        @else
            <div class="text-center py-12">
                <x-icon name="o-magnifying-glass-circle" class="w-16 h-16 mx-auto text-base-content/20 mb-4" />
                <p class="text-lg text-base-content/50">جستجو در تیکت‌ها، کاربران، واحدها و کارهای روزانه</p>
                <p class="text-sm text-base-content/30 mt-2">حداقل ۲ کاراکتر تایپ کنید</p>
            </div>
        @endif
    </x-card>
</div>
