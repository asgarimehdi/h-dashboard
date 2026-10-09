<?php

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

return new class extends Component
{
    public string $query = '';

    public array $results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => []];

    public bool $hasSearched = false;

    public bool $showHelpModal = false;

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
        if (strlen($this->query) < 2) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $q = $this->query;
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        // Issue #897: mirror users/index.blade.php:404-410 — person.u_id in
        // scope OR a user_units row in scope. whereHas('person') alone drops
        // person-less admin accounts that users.index lists.
        $userIds = User::query()
            ->where(function (Builder $query) use ($accessibleIds) {
                $query->whereHas('person', fn (Builder $personQuery) => $personQuery->whereIn('u_id', $accessibleIds))
                    ->orWhereHas('units', fn (Builder $unitQuery) => $unitQuery->whereIn('units.id', $accessibleIds));
            })
            ->pluck('id')
            ->toArray();

        // Issue #897: gate INSIDE search(), before the cache — a route-level
        // role_or_permission middleware is NOT re-applied on
        // POST /livewire/update (#892), so updatedQuery()/search() stays
        // reachable from an already-serialized snapshot without this check.
        $canViewTickets = $user->canAny(['view_assigned_tickets', 'view_all_tickets']);
        $canViewUsers = $user->can('manage_users');

        // Issue #897: versioned per-user key. The old
        // global_search:md5(...) key collided across users with identical
        // scopes and bypassed CacheInvalidationService invalidation.
        // cacheKey() prefixes {namespace}:v{version}: so a namespace bump
        // invalidates; uid + gate flags in $extra isolate users from each
        // other. TTL stays 30s as before — remember() would reinterpret it
        // as minutes.
        $scopeHash = md5($q.':'.implode(',', $accessibleIds).':'.implode(',', $userIds));
        $extraHash = md5(serialize(['uid' => $user->id, 'tickets' => $canViewTickets, 'users' => $canViewUsers]));
        $cacheKey = app(CacheInvalidationServiceInterface::class)->cacheKey('global_search', $scopeHash, $extraHash);

        $this->results = Cache::remember($cacheKey, 30, function () use ($q, $accessibleIds, $userIds, $canViewTickets, $canViewUsers) {
            // Split query into words for multi-word search (e.g. "مهدی عسگری")
            $words = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);

            return [
                // Issue #897: projections, not models — Blade reads only
                // id/subject/ticket_code/status + unit name.
                'tickets' => $canViewTickets ? Ticket::accessible()
                    ->where(function ($query) use ($words) {
                        foreach ($words as $word) {
                            $query->where(function ($inner) use ($word) {
                                $inner->where('subject', 'like', "%{$word}%")
                                    ->orWhere('ticket_code', 'like', "%{$word}%");
                            });
                        }
                    })
                    ->with(['unit:id,name'])
                    ->latest()
                    ->take(10)
                    ->get(['id', 'ticket_code', 'subject', 'status', 'unit_id'])
                    ->map(fn (Ticket $ticket) => [
                        'id' => $ticket->id,
                        'ticket_code' => $ticket->ticket_code,
                        'subject' => $ticket->subject,
                        'status' => $ticket->status,
                        'unit' => $ticket->unit->only(['id', 'name']),
                    ])
                    ->all() : [],

                // Issue #897: projections, not models — Blade reads only
                // title/start_at/is_completed.
                'todos' => Todo::accessible()
                    ->where(function ($query) use ($words) {
                        foreach ($words as $word) {
                            $query->where('title', 'like', "%{$word}%");
                        }
                    })
                    ->latest()
                    ->take(10)
                    ->get(['id', 'title', 'start_at', 'is_completed'])
                    ->map(fn (Todo $todo) => [
                        'id' => $todo->id,
                        'title' => $todo->title,
                        'start_at' => $todo->start_at?->toDateTimeString(),
                        'is_completed' => (bool) $todo->is_completed,
                    ])
                    ->all(),

                // Issue #897: behind manage_users; projection covers only the
                // person f_name/l_name + semat name Blade renders.
                'users' => $canViewUsers ? User::with(['person:id,n_code,f_name,l_name,s_id', 'person.semat:id,name'])
                    ->whereIn('id', $userIds)
                    ->whereHas('person', function ($query) use ($words) {
                        foreach ($words as $word) {
                            $query->where(function ($inner) use ($word) {
                                $inner->where('f_name', 'like', "%{$word}%")
                                    ->orWhere('l_name', 'like', "%{$word}%");
                            });
                        }
                    })
                    ->take(10)
                    ->get(['id', 'n_code'])
                    ->map(fn (User $found) => [
                        'id' => $found->id,
                        'person' => $found->person ? [
                            'f_name' => $found->person->f_name,
                            'l_name' => $found->person->l_name,
                            'semat' => $found->person->semat->only(['name']),
                        ] : null,
                    ])
                    ->all() : [],

                // Issue #897: projections, not models — Blade reads only name.
                'units' => Unit::whereIn('id', $accessibleIds)
                    ->where(function ($query) use ($words) {
                        foreach ($words as $word) {
                            $query->where('name', 'like', "%{$word}%");
                        }
                    })
                    ->take(10)
                    ->get(['id', 'name'])
                    ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name])
                    ->all(),
            ];
        });

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
        return match ($status) {
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
            <x-help:button section="search" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

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
                @canany(['view_assigned_tickets', 'view_all_tickets'])
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
                @endcanany

                {{-- کاربران --}}
                @can('manage_users')
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
                @endcan

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
