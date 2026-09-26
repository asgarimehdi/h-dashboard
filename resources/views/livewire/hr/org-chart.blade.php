<?php

use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * HR org chart — thin composition over the generic <livewire:unit.tree>.
 *
 * Tree mechanics (roots, lazy children, expand/collapse, search) live in
 * `unit.tree`; this page only owns the personnel detail panel and reacts to
 * the tree's `unit-selected` event. See issue #704.
 */
return new class extends Component
{
    use Toast;

    /** @var Unit|null */
    public $selectedUnit;

    /** @var Collection<int, Person> */
    public $selectedPersonnel;

    public int $selectedPersonnelTotal = 0;

    public int $descendantPersonnelTotal = 0;

    public int $directUserCount = 0;

    public int $descendantUserCount = 0;

    /**
     * The generic tree dispatched a node click. Fill the personnel panel.
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
    {{-- Generic tree: supplies roots/children/search/toggle and the badge slot.
         This page keeps the two-column layout and owns the detail panel,
         filled from the tree's `unit-selected` event. --}}
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6" dir="rtl">
        <div class="lg:col-span-3">
            <livewire:unit.tree badge-view="livewire.hr.personnel-badge" title="چارت سازمانی" />
        </div>

        {{-- جزئیات واحد انتخاب شده --}}
        <div class="lg:col-span-1 sticky top-4">
            <x-card shadow>
                @if ($selectedUnit)
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
                @else
                    <p class="text-sm opacity-50 text-center py-8">یک واحد را انتخاب کنید</p>
                @endif
            </x-card>
        </div>
    </div>
</div>
