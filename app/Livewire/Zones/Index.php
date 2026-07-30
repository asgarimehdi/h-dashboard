<?php

namespace App\Livewire\Zones;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Zone;
use App\Models\Unit;
use App\Services\AccessService;
use Mary\Traits\Toast;

#[Layout('components.layouts.app')]
#[Title('مدیریت زون‌ها')]
class Index extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public string $sortField = 'name';
    public string $sortDirection = 'asc';
    public int $perPage = 15;

    public bool $showCreateModal = false;
    public bool $showEditModal = false;
    public bool $showUnitsModal = false;
    public ?Zone $editingZone = null;
    public ?Zone $unitsZone = null;

    #[Rule('required|string|max:255|unique:zones,name')]
    public string $name = '';

    #[Rule('nullable|string|max:255|unique:zones,slug')]
    public string $slug = '';

    #[Rule('nullable|string')]
    public string $description = '';

    #[Rule('required|string|size:7|regex:/^#[0-9A-Fa-f]{6}$/')]
    public string $color = '#3B82F6';

    #[Rule('boolean')]
    public bool $is_active = true;

    public array $selectedUnits = [];
    public array $availableUnits = [];

    protected $messages = [
        'name.required' => 'نام زون الزامی است.',
        'name.unique' => 'این نام زون قبلاً ثبت شده است.',
        'color.required' => 'رنگ الزامی است.',
        'color.regex' => 'فرمت رنگ باید هگزادسیمال باشد (مثال: #3B82F6).',
    ];

    public function mount(): void
    {
        $this->loadAvailableUnits();
    }

    public function loadAvailableUnits(): void
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
        $this->availableUnits = Unit::whereIn('id', $accessibleUnitIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function createZone(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function editZone(int $zoneId): void
    {
        $zone = Zone::findOrFail($zoneId);
        $this->editingZone = $zone;
        $this->name = $zone->name;
        $this->slug = $zone->slug;
        $this->description = $zone->description ?? '';
        $this->color = $zone->color;
        $this->is_active = $zone->is_active;
        $this->showEditModal = true;
    }

    public function saveZone(): void
    {
        $this->validate();

        if ($this->showCreateModal) {
            Zone::create([
                'name' => $this->name,
                'slug' => $this->slug ?: \Illuminate\Support\Str::slug($this->name),
                'description' => $this->description,
                'color' => $this->color,
                'is_active' => $this->is_active,
            ]);
            $this->success('زونا با موفقیت ایجاد شد.');
        } else {
            $this->editingZone->update([
                'name' => $this->name,
                'slug' => $this->slug ?: \Illuminate\Support\Str::slug($this->name),
                'description' => $this->description,
                'color' => $this->color,
                'is_active' => $this->is_active,
            ]);
            $this->success('زونا با موفقیت به‌روزرسانی شد.');
        }

        $this->closeModals();
    }

    public function deleteZone(int $zoneId): void
    {
        $zone = Zone::findOrFail($zoneId);
        $zone->delete();
        $this->success('زونا با موفقیت حذف شد.');
    }

    public function manageUnits(int $zoneId): void
    {
        $this->unitsZone = Zone::findOrFail($zoneId);
        $this->selectedUnits = $this->unitsZone->units()->pluck('units.id')->toArray();
        $this->showUnitsModal = true;
    }

    public function saveUnits(): void
    {
        $this->unitsZone->units()->sync(
            array_combine($this->selectedUnits, array_fill(0, count($this->selectedUnits), ['assigned_at' => now()]))
        );
        $this->success('واحدهای زونا با موفقیت به‌روزرسانی شدند.');
        $this->closeModals();
    }

    public function closeModals(): void
    {
        $this->showCreateModal = false;
        $this->showEditModal = false;
        $this->showUnitsModal = false;
        $this->editingZone = null;
        $this->unitsZone = null;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->description = '';
        $this->color = '#3B82F6';
        $this->is_active = true;
        $this->selectedUnits = [];
        $this->resetValidation();
    }

    public function render()
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();

        $zones = Zone::query()
            ->when($this->search, function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            })
            ->withCount(['units' => function ($q) use ($accessibleUnitIds) {
                $q->whereIn('units.id', $accessibleUnitIds);
            }])
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);

        return view('livewire.zones.index', [
            'zones' => $zones,
        ]);
    }
}