<?php

namespace App\Livewire\Zones;

use App\Models\Zone;
use App\Models\Unit;
use App\Services\AccessService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

#[Layout('components.layouts.app')]
#[Title('ویرایش زون')]
class Edit extends Component
{
    use WithFileUploads, Toast;

    public Zone $zone;

    public string $name = '';
    public string $description = '';
    public string $color = '#3B82F6';
    public bool $is_active = true;
    public array $selectedUnits = [];
    public array $availableUnits = [];

    protected $rules = [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'color' => 'required|string|size:7|regex:/^#[0-9A-Fa-f]{6}$/',
        'is_active' => 'boolean',
    ];

    protected $messages = [
        'name.required' => 'نام زون الزامی است.',
        'color.required' => 'رنگ الزامی است.',
        'color.regex' => 'فرمت رنگ باید هگزادسیمال باشد (مثال: #3B82F6).',
    ];

    public function mount(Zone $zone)
    {
        $this->zone = $zone;
        $this->name = $zone->name;
        $this->description = $zone->description ?? '';
        $this->color = $zone->color;
        $this->is_active = $zone->is_active;
        $this->selectedUnits = $zone->units()->pluck('units.id')->toArray();
        $this->loadAvailableUnits();
    }

    public function loadAvailableUnits()
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
        $this->availableUnits = Unit::whereIn('id', $accessibleUnitIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function save()
    {
        $this->validate();

        $this->zone->update([
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
        ]);

        $this->zone->units()->sync(
            array_combine($this->selectedUnits, array_fill(0, count($this->selectedUnits), ['assigned_at' => now()]))
        );

        $this->success('زونا با موفقیت به‌روزرسانی شد.');
        return redirect()->route('zones.index');
    }

    public function cancel()
    {
        return redirect()->route('zones.index');
    }

    public function render()
    {
        return view('livewire.zones.edit');
    }
}