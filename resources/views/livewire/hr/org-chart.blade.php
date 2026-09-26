<?php

use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * HR چارت سازمانی — thin composition over the reusable `unit.tree` (#704).
 *
 * The page owns ONLY the personnel-specific parts: the per-node counts handed
 * to the tree as badge data, and the detail panel filled when the tree
 * dispatches `unit-selected`. All tree mechanics live in `unit.tree`.
 */
return new class extends Component
{
    use Toast;

    /** @var \App\Models\Unit|null Currently selected unit (null until the tree fires `unit-selected`). */
    public $selectedUnit;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Person>|null */
    public $selectedPersonnel;

    public int $selectedPersonnelTotal = 0;

    public int $descendantPersonnelTotal = 0;

    /**
     * Personnel per accessible unit — fed to `unit.tree` as `badge-data`.
     *
     * @var array<int, int>
     */
    public array $personCounts = [];

    public int $directUserCount = 0;

    public int $descendantUserCount = 0;

    public function mount(): void
    {
        $this->loadPersonCounts();
    }

    /**
     * Personnel counts for all accessible units (one grouped query).
     */
    public function loadPersonCounts(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        $this->personCounts = Person::whereIn('u_id', $accessibleIds)
            ->selectRaw('u_id, count(*) as cnt')
            ->groupBy('u_id')
            ->pluck('cnt', 'u_id')
            ->toArray();
    }

    /**
     * The tree fired `unit-selected` — load the unit's detail panel.
     * Access check + the four queries below are the original selectUnit(),
     * moved verbatim from when the tree lived inside this component.
     */
    #[On('unit-selected')]
    public function selectUnit(int $id): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if (! in_array($id, $accessibleIds)) {
            $this->error('شما مجاز به مشاهده این واحد نیستید.', position: 'toast-bottom');

            return;
        }

        // Query 1: Unit with relations
        $this->selectedUnit = Unit::with(['parent', 'unitType', 'assignedUsers.person'])->find($id);

        // Query 2: Personnel + total count (clone query, no separate count query)
        $personQuery = Person::where('u_id', $id)->with(['semat', 'tahsil', 'estekhdam', 'radif', 'user']);
        $this->selectedPersonnelTotal = (clone $personQuery)->count();
        $this->selectedPersonnel = $personQuery->limit(20)->get();

        // Query 3: Descendant personnel + user counts (descendantIds is cached)
        $descendantIds = Unit::descendantIds($id);
        $this->descendantPersonnelTotal = $descendantIds->isNotEmpty()
            ? Person::whereIn('u_id', $descendantIds)->count()
            : 0;

        // Query 4: Descendant user count via direct JOIN (faster than whereHas)
        $this->descendantUserCount = $descendantIds->isNotEmpty()
            ? DB::table('user_units')
                ->whereIn('unit_id', $descendantIds)
                ->distinct()
                ->count('user_id')
            : 0;

        $this->directUserCount = $this->selectedUnit->assignedUsers->count();
    }
};
?>

<div>
    <x-header title="چارت سازمانی" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6" dir="rtl">
        <div class="lg:col-span-3">
            {{-- The reusable tree: it renders its own controls, card and badges (#704) --}}
            <livewire:unit.tree
                badge-view="livewire.hr.personnel-badge"
                :badge-data="$personCounts"
                search-placeholder="جستجوی واحد..."
            />
        </div>

        {{-- جزئیات واحد انتخاب شده --}}
        <div class="lg:col-span-1 sticky top-4">
            @if($selectedUnit)
            <x-card shadow>
                <h3 class="font-bold mb-3">{{ $selectedUnit->name }}</h3>
                <div class="space-y-2 text-sm">
                    <div><span class="font-bold">نوع:</span> {{ $selectedUnit->unitType?->name ?? '---' }}</div>
                    <div><span class="font-bold">والد:</span> {{ $selectedUnit->parent?->name ?? '---' }}</div>
                    <div><span class="font-bold">پرسنل مستقیم:</span> {{ $selectedPersonnelTotal }} نفر <span class="text-xs opacity-60">(زیرمجموعه: {{ $descendantPersonnelTotal }} نفر)</span></div>
                    <div><span class="font-bold">کاربران مستقیم:</span> {{ $directUserCount }} نفر <span class="text-xs opacity-60">(زیرمجموعه: {{ $descendantUserCount }} نفر)</span></div>
                </div>
                <div class="mt-4">
                    <h4 class="font-bold text-xs mb-2">پرسنل این واحد (۲۰ نفر اول):</h4>
                    @forelse($selectedPersonnel as $p)
                    <div class="flex items-center gap-2 p-2 bg-base-200/50 rounded mb-1">
                        <x-icon name="o-user" class="w-4 h-4 {{ $p->user ? 'text-success' : 'text-error' }}" />
                        <span class="text-xs">{{ $p->f_name }} {{ $p->l_name }}</span>
                        <span class="badge badge-xs badge-ghost">{{ $p->semat?->name ?? '---' }}</span>
                    </div>
                    @empty
                    <p class="text-xs opacity-50">پرسنلی ندارد</p>
                    @endforelse
                </div>
            </x-card>
            @else
            <x-card shadow>
                <p class="text-sm opacity-50 text-center py-8">یک واحد را انتخاب کنید</p>
            </x-card>
            @endif
        </div>
    </div>
</div>
