<?php

namespace App\Livewire\Hr;

use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use Livewire\Component;
use Illuminate\Support\Facades\Cache;

class Dashboard extends Component
{
    public array $stats = [];
    public array $byUnit = [];
    public array $bySemat = [];
    public array $byTahsil = [];
    public array $byEstekhdam = [];
    public array $byRadif = [];
    public array $vacancies = [];

    public function mount(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // Cache heavy aggregations (5 min) — Issue #223 perf note
        // Versioned by hr_stats_version so Person saved/deleted invalidates it immediately (#375)
        $version = Cache::get('hr_stats_version', 0);
        $cacheKey = 'hr:dashboard:v' . $version . ':' . md5(implode(',', $accessibleIds));
        $data = Cache::remember($cacheKey, 300, function () use ($accessibleIds) {
            $persons = Person::whereIn('u_id', $accessibleIds);

            return [
                'total' => (clone $persons)->count(),
                'active' => (clone $persons)->where('status', 'active')->count(),
                'retired' => (clone $persons)->where('status', 'retired')->count(),
                'by_unit' => (clone $persons)->selectRaw('u_id, count(*) as total')
                    ->groupBy('u_id')->with('unit:id,name')->get()->map(fn ($p) => [
                        'name' => $p->unit?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_semat' => (clone $persons)->selectRaw('s_id, count(*) as total')
                    ->whereNotNull('s_id')->groupBy('s_id')->with('semat:id,name')->get()->map(fn ($p) => [
                        'name' => $p->semat?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_tahsil' => (clone $persons)->selectRaw('t_id, count(*) as total')
                    ->whereNotNull('t_id')->groupBy('t_id')->with('tahsil:id,name')->get()->map(fn ($p) => [
                        'name' => $p->tahsil?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_estekhdam' => (clone $persons)->selectRaw('e_id, count(*) as total')
                    ->whereNotNull('e_id')->groupBy('e_id')->with('estekhdam:id,name')->get()->map(fn ($p) => [
                        'name' => $p->estekhdam?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
                'by_radif' => (clone $persons)->selectRaw('r_id, count(*) as total')
                    ->whereNotNull('r_id')->groupBy('r_id')->with('radif:id,name')->get()->map(fn ($p) => [
                        'name' => $p->radif?->name ?? '—',
                        'total' => $p->total,
                    ])->values()->toArray(),
            ];
        });

        $this->stats = $data;
        $this->byUnit = $data['by_unit'];
        $this->bySemat = $data['by_semat'];
        $this->byTahsil = $data['by_tahsil'];
        $this->byEstekhdam = $data['by_estekhdam'];
        $this->byRadif = $data['by_radif'];

        // Vacancies: units with zero personnel
        $this->vacancies = Unit::whereIn('id', $accessibleIds)
            ->withCount('person as personnel_count')
            ->get()
            ->filter(fn ($u) => $u->personnel_count === 0)
            ->take(10)
            ->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.hr.dashboard');
    }
}
